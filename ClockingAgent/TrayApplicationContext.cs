using System.Diagnostics;
using Microsoft.Win32;
using System.Windows.Forms;

public sealed class TrayApplicationContext : ApplicationContext
{
    private readonly AgentOptions _options;
    private readonly HttpClient _httpClient = new() { Timeout = TimeSpan.FromSeconds(30) };
    private readonly WindowsEventSender _sender;
    private readonly AgentUpdater _updater;
    private readonly NotifyIcon _notifyIcon;
    private readonly System.Windows.Forms.Timer _timer;
    private readonly System.Windows.Forms.Timer _reminderTimer;
    private readonly System.Windows.Forms.Timer _updateTimer;
    private readonly ActivityTracker _activityTracker = new();
    private readonly ToolStripMenuItem _statusItem;
    private readonly Dictionary<AttendanceAction, ToolStripMenuItem> _attendanceItems = [];
    private readonly CancellationTokenSource _shutdown = new();
    private AttendanceState _attendanceState = AttendanceState.Unknown;
    private EmployeeScheduleResult? _schedule;
    private DateTime _scheduleFetchedAt = DateTime.MinValue;
    private ShiftConfirmationForm? _shiftConfirmationForm;
    private DateTime _clockInSnoozeUntil = DateTime.MinValue;
    private DateTime _clockOutSnoozeUntil = DateTime.MinValue;
    private string? _confirmedClockInKey;
    private string? _confirmedClockOutKey;
    private bool _isSending;
    private bool _isPostingAttendance;
    private bool _isCheckingForUpdates;
    private bool _isCheckingReminders;
    private bool _isOvertimeSession;
    private bool _reportedAgentExit;
    private string _lastAttendanceError = "";
    private const int MinimumLoggedIdleSeconds = 300;

    public TrayApplicationContext(AgentOptions options)
    {
        _options = options;
        _sender = new WindowsEventSender(_httpClient, _options);
        _updater = new AgentUpdater(_httpClient, _options);

        _statusItem = new ToolStripMenuItem("Starting...")
        {
            Enabled = false
        };

        var menu = new ContextMenuStrip();
        menu.Items.Add(_statusItem);
        menu.Items.Add(new ToolStripSeparator());
        AddAttendanceItem(menu, AttendanceAction.ClockIn);
        AddAttendanceItem(menu, AttendanceAction.ClockOut);
        AddAttendanceItem(menu, AttendanceAction.StartOvertime);
        AddAttendanceItem(menu, AttendanceAction.EndOvertime);
        menu.Items.Add(new ToolStripSeparator());
        AddAttendanceItem(menu, AttendanceAction.StartBreak);
        AddAttendanceItem(menu, AttendanceAction.EndBreak);
        AddAttendanceItem(menu, AttendanceAction.StartLunch);
        AddAttendanceItem(menu, AttendanceAction.EndLunch);
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Settings", null, (_, _) => OpenProtectedSettings());
        menu.Items.Add("Open web app", null, (_, _) => OpenUrl(_options.ApiBaseUrl));
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Exit", null, async (_, _) => await ExitAgentAsync());

        _notifyIcon = new NotifyIcon
        {
            ContextMenuStrip = menu,
            Icon = TrayIconFactory.Create(),
            Text = "Clocking Agent",
            Visible = true
        };
        _notifyIcon.DoubleClick += (_, _) => OpenSettings();

        _timer = new System.Windows.Forms.Timer
        {
            Interval = Math.Max(5, _options.IntervalSeconds) * 1000
        };
        _timer.Tick += async (_, _) => await SendNowAsync();

        _reminderTimer = new System.Windows.Forms.Timer
        {
            Interval = 60 * 1000
        };
        _reminderTimer.Tick += async (_, _) => await CheckShiftRemindersAsync();

        _updateTimer = new System.Windows.Forms.Timer
        {
            Interval = 6 * 60 * 60 * 1000
        };
        _updateTimer.Tick += async (_, _) => await CheckForUpdatesAsync();

        SetStatus(_options.IsConfigured ? "Ready" : "Configure device token");
        _notifyIcon.ShowBalloonTip(
            3000,
            "Clocking Agent",
            _options.IsConfigured ? "Running in the tray." : "Open Settings to add your device token.",
            ToolTipIcon.Info
        );
        ApplyAttendanceState(AttendanceState.Unknown);
        _timer.Start();
        _reminderTimer.Start();
        _updateTimer.Start();
        _ = SendNowAsync();
        // Run status and reminder checks in order. Starting both independently can
        // make the reminder use the initial Unknown state and miss its foreground
        // window until the next timer tick or attendance action.
        _ = InitializeAttendanceAsync();
        _ = CheckForUpdatesAsync();
        _ = SendSystemEventAsync("login", "agent_start");

        SystemEvents.SessionSwitch += OnSessionSwitch;
        SystemEvents.PowerModeChanged += OnPowerModeChanged;
        SystemEvents.SessionEnding += OnSessionEnding;

        if (!_options.IsConfigured)
        {
            Application.Idle += OpenInitialSetup;
        }
    }

    private void OpenInitialSetup(object? sender, EventArgs e)
    {
        Application.Idle -= OpenInitialSetup;
        OpenSettings();
    }

    private void AddAttendanceItem(ContextMenuStrip menu, AttendanceAction action)
    {
        var item = new ToolStripMenuItem(action.Label, null, async (_, _) => await HandleTrayAttendanceAsync(action));
        _attendanceItems[action] = item;
        menu.Items.Add(item);
    }

    private async Task HandleTrayAttendanceAsync(AttendanceAction action)
    {
        var success = action == AttendanceAction.ClockOut
            ? await ConfirmShiftActionAsync(ShiftConfirmationKind.ClockOut, "tray_menu")
            : await PostAttendanceAsync(action);

        if (!success && !string.IsNullOrWhiteSpace(_lastAttendanceError))
        {
            MessageBox.Show(
                _lastAttendanceError,
                $"{action.Label} failed",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error
            );
        }
    }

    private async Task SendNowAsync()
    {
        if (_isSending)
        {
            return;
        }

        if (!_options.IsConfigured)
        {
            SetStatus("Missing valid device token");
            return;
        }

        try
        {
            _isSending = true;

            var idleSeconds = IdleTimeHelper.GetIdleSeconds();
            var isIdle = idleSeconds >= MinimumLoggedIdleSeconds;
            var activity = _activityTracker.Observe(isIdle);

            if (activity is not null)
            {
                var activityResult = await _sender.SendActivitySliceAsync(activity, _shutdown.Token);
                if (!activityResult.Success)
                {
                    SetStatus($"Activity failed: {activityResult.Message}");
                }
            }

            if (!isIdle)
            {
                var activeResult = await _sender.SendWindowsEventAsync(
                    "activity",
                    idleSeconds,
                    _shutdown.Token,
                    new Dictionary<string, string> { ["reason"] = "keyboard_mouse_activity" }
                );
                SetStatus(activeResult.Success
                    ? $"Active {DateTime.Now:t}"
                    : $"Activity heartbeat failed: {activeResult.Message}");
                return;
            }

            SetStatus($"Sending idle {FormatDuration(idleSeconds)}...");
            var result = await _sender.SendIdleEventAsync(idleSeconds, _shutdown.Token);
            SetStatus(result.Success
                ? $"Last sent {DateTime.Now:t} - idle {FormatDuration(result.IdleSeconds)}"
                : $"Send failed: {result.Message}");
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // The app is closing.
        }
        finally
        {
            _isSending = false;
        }
    }

    private async Task<bool> PostAttendanceAsync(
        AttendanceAction action,
        string confirmationSource = "tray_menu",
        string? completedShiftKey = null
    )
    {
        if (_isPostingAttendance)
        {
            return false;
        }

        if (!_options.IsConfigured)
        {
            SetStatus("Missing valid device token");
            return false;
        }

        try
        {
            _isPostingAttendance = true;
            SetAttendanceItemsEnabled(false);
            SetStatus($"{action.Label}...");

            // Capture the session key before the server refresh changes a missing
            // clock-out into NotClockedIn. This also covers employees resolving a
            // prior-day miss from the tray menu rather than the reminder window.
            var inferredCompletedShiftKey = completedShiftKey;
            if (action == AttendanceAction.ClockOut && inferredCompletedShiftKey is null)
            {
                inferredCompletedShiftKey = CurrentShiftKey();
            }

            var result = await _sender.SendAttendanceActionAsync(action, _shutdown.Token, confirmationSource);
            _lastAttendanceError = result.Success ? "" : result.Message;
            SetStatus(result.Success
                ? $"{action.Label} recorded {DateTime.Now:t}"
                : $"{action.Label} failed: {result.Message}");

            if (result.Success)
            {
                await RefreshAttendanceStatusAsync();

                // A successful clock-out completes the current scheduled shift. Mark it
                // before rechecking reminders so the resulting NotClockedIn state cannot
                // be mistaken for a missed clock-in during the five-minute clock-out window.
                if (action == AttendanceAction.ClockOut)
                {
                    var shiftKey = inferredCompletedShiftKey ?? CurrentShiftKey();
                    _confirmedClockInKey = shiftKey;
                    _confirmedClockOutKey = shiftKey;
                }

                await CheckShiftRemindersAsync(forceScheduleRefresh: true);
            }
            return result.Success;
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // The app is closing.
            return false;
        }
        finally
        {
            ApplyAttendanceState(_attendanceState);
            _isPostingAttendance = false;
        }
    }

    private async Task InitializeAttendanceAsync()
    {
        if (!_options.IsConfigured)
        {
            return;
        }

        await RefreshAttendanceStatusAsync();
        await CheckShiftRemindersAsync(forceScheduleRefresh: true);
    }

    private async void OnSessionSwitch(object sender, SessionSwitchEventArgs e)
    {
        var eventCode = e.Reason switch
        {
            SessionSwitchReason.SessionLock => "lock",
            SessionSwitchReason.SessionUnlock => "unlock",
            SessionSwitchReason.SessionLogon => "login",
            SessionSwitchReason.SessionLogoff => "logout",
            _ => null
        };

        if (eventCode is not null)
        {
            await SendSystemEventAsync(eventCode, e.Reason.ToString());
            await CheckShiftRemindersAsync(forceScheduleRefresh: true);
        }
    }

    private async void OnPowerModeChanged(object sender, PowerModeChangedEventArgs e)
    {
        var eventCode = e.Mode switch
        {
            PowerModes.Suspend => "sleep",
            PowerModes.Resume => "wake",
            _ => null
        };

        if (eventCode is not null)
        {
            await SendSystemEventAsync(eventCode, e.Mode.ToString());
            await CheckShiftRemindersAsync(forceScheduleRefresh: true);
        }
    }

    private async void OnSessionEnding(object sender, SessionEndingEventArgs e)
    {
        var eventCode = e.Reason == SessionEndReasons.SystemShutdown ? "shutdown" : "logout";
        await SendSystemEventAsync(eventCode, e.Reason.ToString());
    }

    private async Task SendSystemEventAsync(string eventCode, string reason)
    {
        if (!_options.IsConfigured || _shutdown.IsCancellationRequested)
        {
            return;
        }

        // State can change on the server (including an offline shift being
        // closed at its scheduled end), so do not rely on the startup snapshot.
        if (!_isPostingAttendance)
        {
            await RefreshAttendanceStatusAsync();
        }

        try
        {
            var idleSeconds = IdleTimeHelper.GetIdleSeconds();
            var result = await _sender.SendWindowsEventAsync(
                eventCode,
                idleSeconds,
                _shutdown.Token,
                new Dictionary<string, string> { ["reason"] = reason }
            );

            SetStatus(result.Success
                ? $"Windows {eventCode} sent {DateTime.Now:t}"
                : $"Windows {eventCode} failed: {result.Message}");
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // The app is closing.
        }
    }

    private void OpenSettings()
    {
        using var form = new SettingsForm(_options);
        if (form.ShowDialog() != DialogResult.OK)
        {
            return;
        }

        _options.Apply(form.Result);
        _timer.Interval = Math.Max(5, _options.IntervalSeconds) * 1000;
        _schedule = null;
        _scheduleFetchedAt = DateTime.MinValue;
        SetStatus("Settings saved");
        _ = InitializeAttendanceAsync();
    }

    private void OpenProtectedSettings()
    {
        // Upgrades from agents before 1.1.0 have no local control password yet.
        // Allow setup so an administrator can relink once and establish it.
        if (string.IsNullOrWhiteSpace(_options.ControlPasswordHash))
        {
            MessageBox.Show(
                "This upgraded agent needs to be linked once to enable administrator password protection.",
                "Administrator setup required",
                MessageBoxButtons.OK,
                MessageBoxIcon.Information
            );
            OpenSettings();
            return;
        }
        if (!Authorize("change agent settings")) return;
        OpenSettings();
    }

    private bool Authorize(string action)
    {
        if (string.IsNullOrWhiteSpace(_options.ControlPasswordHash)) return false;
        using var prompt = new PasswordPrompt(action);
        if (prompt.ShowDialog() != DialogResult.OK) return false;
        if (PasswordProtection.Verify(prompt.Password, _options.ControlPasswordHash)) return true;
        MessageBox.Show("Incorrect administrator password.", "Access denied", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        return false;
    }

    private void SetAttendanceItemsEnabled(bool enabled)
    {
        foreach (var item in _attendanceItems.Values)
        {
            item.Enabled = enabled;
        }
    }

    private async Task RefreshAttendanceStatusAsync()
    {
        if (!_options.IsConfigured)
        {
            ApplyAttendanceState(AttendanceState.Unknown);
            return;
        }

        var result = await _sender.GetAttendanceStatusAsync(_shutdown.Token);
        if (!result.Success)
        {
            _isOvertimeSession = false;
            ApplyAttendanceState(AttendanceState.Unknown);
            SetStatus($"Status unavailable: {result.Message}");
            return;
        }

        _isOvertimeSession = result.IsOvertime;
        ApplyAttendanceState(result.State);
    }

    private async Task CheckShiftRemindersAsync(bool forceScheduleRefresh = false)
    {
        if (_isCheckingReminders || !_options.IsConfigured || _shutdown.IsCancellationRequested)
        {
            return;
        }

        try
        {
            _isCheckingReminders = true;
            if (forceScheduleRefresh || _schedule is null || DateTime.Now - _scheduleFetchedAt > TimeSpan.FromMinutes(15))
            {
                _schedule = await _sender.GetEmployeeScheduleAsync(_shutdown.Token);
                _scheduleFetchedAt = DateTime.Now;
            }

            if (_schedule is not { Success: true })
            {
                return;
            }

            if (_schedule.IsFlexible)
            {
                CloseConfirmationWindow();
                SetAttendanceItemsEnabled(false);
                SetStatus("Flexible attendance · activity tracked automatically");
                return;
            }

        await RefreshAttendanceStatusAsync();
        var now = ScheduleNow(_schedule.Timezone);
        var today = DateOnly.FromDateTime(now);
        var shiftDate = today;
        if ((_schedule.SpansMidnight || _schedule.EndTime <= _schedule.StartTime)
            && TimeOnly.FromDateTime(now) <= _schedule.EndTime)
        {
            shiftDate = today.AddDays(-1);
        }

        var shiftStart = shiftDate.ToDateTime(_schedule.StartTime);
        var shiftEnd = shiftDate.ToDateTime(_schedule.EndTime);
        if (_schedule.SpansMidnight || _schedule.EndTime <= _schedule.StartTime) shiftEnd = shiftEnd.AddDays(1);
        var clockOutWindow = shiftEnd.AddMinutes(-5);

        // Once today's date has started, the normal schedule calculation points at
        // today's shift. A missing clock-out, however, belongs to the most recent
        // completed scheduled shift. Keep targeting that prior shift until it has
        // been resolved instead of postponing the reminder until today's shift end.
        if (_attendanceState == AttendanceState.MissingClockOut
            && shiftDate == today && now < clockOutWindow)
        {
            shiftDate = PreviousScheduledDate(_schedule, today);
            shiftStart = shiftDate.ToDateTime(_schedule.StartTime);
            shiftEnd = shiftDate.ToDateTime(_schedule.EndTime);
            if (_schedule.SpansMidnight || _schedule.EndTime <= _schedule.StartTime) shiftEnd = shiftEnd.AddDays(1);
            clockOutWindow = shiftEnd.AddMinutes(-5);
        }

        if (!IsScheduledDay(_schedule, shiftDate))
        {
            CloseConfirmationWindow();
            return;
        }

        var key = shiftDate.ToString("yyyy-MM-dd");
        var clockInWindow = shiftStart.AddMinutes(-5);

        if (_attendanceState == AttendanceState.NotClockedIn
            && now >= clockInWindow && now < shiftEnd
            && _confirmedClockInKey != key && _confirmedClockOutKey != key
            && now >= _clockInSnoozeUntil)
        {
            ShowConfirmationWindow(ShiftConfirmationKind.ClockIn, key, shiftStart);
            return;
        }

        if ((_attendanceState is AttendanceState.Working or AttendanceState.OnBreak or AttendanceState.OnLunch or AttendanceState.MissingClockOut)
            && now >= clockOutWindow && now < shiftEnd.AddHours(12)
            && _confirmedClockOutKey != key && now >= _clockOutSnoozeUntil)
        {
            ShowConfirmationWindow(ShiftConfirmationKind.ClockOut, key, shiftEnd);
            return;
        }

            if (((_attendanceState is AttendanceState.Working or AttendanceState.OnBreak or AttendanceState.OnLunch) && now < clockOutWindow)
                || (_attendanceState == AttendanceState.NotClockedIn && now < clockInWindow)
                || _attendanceState == AttendanceState.MissingClockOut && now < clockOutWindow)
            {
                CloseConfirmationWindow();
            }
        }
        finally
        {
            _isCheckingReminders = false;
        }
    }

    private string CurrentShiftKey()
    {
        var now = _schedule is null ? DateTime.Now : ScheduleNow(_schedule.Timezone);
        var shiftDate = DateOnly.FromDateTime(now);

        if (_schedule is not null
            && (_schedule.SpansMidnight || _schedule.EndTime <= _schedule.StartTime)
            && TimeOnly.FromDateTime(now) <= _schedule.EndTime)
        {
            shiftDate = shiftDate.AddDays(-1);
        }

        if (_schedule is { Success: true }
            && _attendanceState == AttendanceState.MissingClockOut
            && shiftDate == DateOnly.FromDateTime(now))
        {
            var shiftEnd = shiftDate.ToDateTime(_schedule.EndTime);
            if (_schedule.SpansMidnight || _schedule.EndTime <= _schedule.StartTime) shiftEnd = shiftEnd.AddDays(1);
            if (now < shiftEnd.AddMinutes(-5))
            {
                shiftDate = PreviousScheduledDate(_schedule, shiftDate);
            }
        }

        return shiftDate.ToString("yyyy-MM-dd");
    }

    private void ShowConfirmationWindow(ShiftConfirmationKind kind, string key, DateTime shiftMoment)
    {
        if (_shiftConfirmationForm is { IsDisposed: false })
        {
            if (!_shiftConfirmationForm.Visible) _shiftConfirmationForm.Show();
            _shiftConfirmationForm.BringToFront();
            _shiftConfirmationForm.Activate();
            return;
        }

        _shiftConfirmationForm = new ShiftConfirmationForm(
            kind,
            _schedule?.EmployeeName ?? "Employee",
            shiftMoment,
            _schedule?.GracePeriodMinutes ?? 0,
            () => _schedule is null ? DateTime.Now : ScheduleNow(_schedule.Timezone),
            async () =>
            {
                var success = await ConfirmShiftActionAsync(kind, completedShiftKey: key);
                if (success)
                {
                    if (kind == ShiftConfirmationKind.ClockIn) _confirmedClockInKey = key;
                    else _confirmedClockOutKey = key;
                }
                return success;
            },
            () => _lastAttendanceError,
            () =>
            {
                var current = _schedule is null ? DateTime.Now : ScheduleNow(_schedule.Timezone);
                if (kind == ShiftConfirmationKind.ClockIn) _clockInSnoozeUntil = current.AddMinutes(2);
                else _clockOutSnoozeUntil = current.AddMinutes(5);
            }
        );
        _shiftConfirmationForm.FormClosed += (_, _) =>
        {
            _shiftConfirmationForm?.Dispose();
            _shiftConfirmationForm = null;
        };
        _shiftConfirmationForm.Show();
        SetStatus(kind == ShiftConfirmationKind.ClockIn
            ? $"Clock-in confirmation required for {shiftMoment:HH:mm}"
            : $"Clock-out confirmation required for {shiftMoment:HH:mm}");
    }

    private async Task<bool> ConfirmShiftActionAsync(
        ShiftConfirmationKind kind,
        string source = "foreground_shift_confirmation",
        string? completedShiftKey = null
    )
    {
        if (kind == ShiftConfirmationKind.ClockIn)
        {
            return await PostAttendanceAsync(AttendanceAction.ClockIn, source);
        }

        // Attendance transitions require an active break or lunch to be ended first.
        if (_attendanceState == AttendanceState.OnBreak
            && !await PostAttendanceAsync(AttendanceAction.EndBreak, source))
        {
            return false;
        }
        if (_attendanceState == AttendanceState.OnLunch
            && !await PostAttendanceAsync(AttendanceAction.EndLunch, source))
        {
            return false;
        }

        return await PostAttendanceAsync(AttendanceAction.ClockOut, source, completedShiftKey);
    }

    private void CloseConfirmationWindow()
    {
        if (_shiftConfirmationForm is { IsDisposed: false }) _shiftConfirmationForm.Close();
    }

    private async Task CheckForUpdatesAsync()
    {
        if (_isCheckingForUpdates || !_options.IsConfigured || _shutdown.IsCancellationRequested)
        {
            return;
        }

        try
        {
            _isCheckingForUpdates = true;
            var result = await _updater.CheckAndInstallAsync(_shutdown.Token);
            if (result.IsInstalling)
            {
                SetStatus(result.Message);
                _notifyIcon.ShowBalloonTip(3000, "Agent update", result.Message, ToolTipIcon.Info);
                // Release the installed executable so Inno Setup can replace it.
                // This is an authorised update handoff, not a user exit alert.
                _reportedAgentExit = true;
                ExitThread();
            }
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // The app is closing.
        }
        catch (Exception ex)
        {
            Console.WriteLine($"{DateTime.Now}: Update check failed: {ex.Message}");
        }
        finally
        {
            _isCheckingForUpdates = false;
        }
    }

    private async Task ExitAgentAsync()
    {
        if (string.IsNullOrWhiteSpace(_options.ControlPasswordHash))
        {
            MessageBox.Show(
                "Exit is locked, but this upgraded installation has not established its administrator password yet. Open Settings and link this device once, then try again.",
                "Administrator setup required",
                MessageBoxButtons.OK,
                MessageBoxIcon.Information
            );
            return;
        }
        if (!Authorize("exit the agent")) return;
        if (_reportedAgentExit)
        {
            ExitThread();
            return;
        }

        await ReportAgentExitAsync("tray_exit");
        ExitThread();
    }

    private async Task ReportAgentExitAsync(string reason)
    {
        if (!_options.IsConfigured || _reportedAgentExit)
        {
            return;
        }

        try
        {
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
            var result = await _sender.SendWindowsEventAsync(
                "agent_exit",
                null,
                timeout.Token,
                new Dictionary<string, string> { ["reason"] = reason }
            );
            _reportedAgentExit = result.Success;
        }
        catch (Exception ex)
        {
            Console.WriteLine($"{DateTime.Now}: Agent exit report failed: {ex.Message}");
        }
    }

    private static bool IsScheduledDay(EmployeeScheduleResult schedule, DateOnly date)
    {
        var isoDay = date.DayOfWeek == DayOfWeek.Sunday ? 7 : (int) date.DayOfWeek;
        return schedule.ScheduledDays.Contains(isoDay);
    }

    private static DateOnly PreviousScheduledDate(EmployeeScheduleResult schedule, DateOnly date)
    {
        var candidate = date.AddDays(-1);
        for (var daysChecked = 0; daysChecked < 7; daysChecked++, candidate = candidate.AddDays(-1))
        {
            if (IsScheduledDay(schedule, candidate)) return candidate;
        }

        return date.AddDays(-1);
    }

    private static DateTime ScheduleNow(string timezone)
    {
        try
        {
            var zone = TimeZoneInfo.FindSystemTimeZoneById(timezone);
            return TimeZoneInfo.ConvertTime(DateTimeOffset.UtcNow, zone).DateTime;
        }
        catch (TimeZoneNotFoundException) when (!timezone.Equals("South Africa Standard Time", StringComparison.OrdinalIgnoreCase))
        {
            var zone = TimeZoneInfo.FindSystemTimeZoneById("South Africa Standard Time");
            return TimeZoneInfo.ConvertTime(DateTimeOffset.UtcNow, zone).DateTime;
        }
    }

    private void ApplyAttendanceState(AttendanceState state)
    {
        _attendanceState = state;
        if (_schedule is { Success: true, IsFlexible: true })
        {
            SetAttendanceItemsEnabled(false);
            return;
        }
        SetAttendanceItem(AttendanceAction.ClockIn, state == AttendanceState.NotClockedIn);
        SetAttendanceItem(AttendanceAction.ClockOut, state is AttendanceState.Working
            or AttendanceState.OnBreak
            or AttendanceState.OnLunch
            or AttendanceState.MissingClockOut);
        SetAttendanceItem(AttendanceAction.StartBreak, state == AttendanceState.Working);
        SetAttendanceItem(AttendanceAction.EndBreak, state == AttendanceState.OnBreak);
        SetAttendanceItem(AttendanceAction.StartLunch, state == AttendanceState.Working);
        SetAttendanceItem(AttendanceAction.EndLunch, state == AttendanceState.OnLunch);
        SetAttendanceItem(AttendanceAction.StartOvertime, state == AttendanceState.NotClockedIn && _schedule is { Success: true } && TimeOnly.FromDateTime(ScheduleNow(_schedule.Timezone)) >= _schedule.EndTime);
        SetAttendanceItem(AttendanceAction.EndOvertime, state == AttendanceState.Working && _isOvertimeSession);

        if (state == AttendanceState.Unknown)
        {
            SetAttendanceItemsEnabled(false);
        }
    }

    private void SetAttendanceItem(AttendanceAction action, bool enabled)
    {
        if (_attendanceItems.TryGetValue(action, out var item))
        {
            item.Enabled = enabled;
        }
    }

    private void SetStatus(string status)
    {
        _statusItem.Text = status.Length > 80 ? $"{status[..77]}..." : status;
        _notifyIcon.Text = $"Clocking Agent - {_statusItem.Text}"[..Math.Min(63, $"Clocking Agent - {_statusItem.Text}".Length)];
    }

    private static string FormatDuration(int totalSeconds)
    {
        var duration = TimeSpan.FromSeconds(Math.Max(0, totalSeconds));
        if (duration.TotalHours >= 1)
        {
            return $"{(int)duration.TotalHours}h {duration.Minutes}m";
        }

        if (duration.TotalMinutes >= 1)
        {
            return $"{duration.Minutes}m {duration.Seconds}s";
        }

        return $"{duration.Seconds}s";
    }

    private static void OpenUrl(string target)
    {
        if (string.IsNullOrWhiteSpace(target))
        {
            return;
        }

        Process.Start(new ProcessStartInfo
        {
            FileName = target,
            UseShellExecute = true
        });
    }

    protected override void ExitThreadCore()
    {
        if (!_reportedAgentExit && _options.IsConfigured)
        {
            try
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(3));
                _sender.SendWindowsEventAsync(
                    "agent_exit",
                    null,
                    timeout.Token,
                    new Dictionary<string, string> { ["reason"] = "application_exit" }
                ).GetAwaiter().GetResult();
                _reportedAgentExit = true;
            }
            catch (Exception ex) when (ex is not OperationCanceledException)
            {
                Console.WriteLine($"{DateTime.Now}: Agent exit report failed: {ex.Message}");
            }
        }

        SystemEvents.SessionSwitch -= OnSessionSwitch;
        SystemEvents.PowerModeChanged -= OnPowerModeChanged;
        SystemEvents.SessionEnding -= OnSessionEnding;
        _shutdown.Cancel();
        CloseConfirmationWindow();
        _timer.Stop();
        _reminderTimer.Stop();
        _updateTimer.Stop();
        _timer.Dispose();
        _reminderTimer.Dispose();
        _updateTimer.Dispose();
        _notifyIcon.Visible = false;
        _notifyIcon.Dispose();
        _httpClient.Dispose();
        _shutdown.Dispose();

        base.ExitThreadCore();
    }
}
