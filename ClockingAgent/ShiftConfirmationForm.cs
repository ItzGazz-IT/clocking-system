using System.Drawing;
using System.Windows.Forms;

public enum ShiftConfirmationKind
{
    ClockIn,
    ClockOut
}

public sealed class ShiftConfirmationForm : Form
{
    private readonly ShiftConfirmationKind _kind;
    private readonly DateTime _shiftMoment;
    private readonly int _graceMinutes;
    private readonly Func<DateTime> _now;
    private readonly Func<Task<bool>> _confirm;
    private readonly Func<string> _confirmationError;
    private readonly Action _snooze;
    private readonly Label _statusLabel;
    private readonly Button _confirmButton;
    private readonly System.Windows.Forms.Timer _timer;
    private bool _completed;
    private bool _submitting;

    public ShiftConfirmationForm(
        ShiftConfirmationKind kind,
        string employeeName,
        DateTime shiftMoment,
        int graceMinutes,
        Func<DateTime> now,
        Func<Task<bool>> confirm,
        Func<string> confirmationError,
        Action snooze
    )
    {
        _kind = kind;
        _shiftMoment = shiftMoment;
        _graceMinutes = Math.Max(0, graceMinutes);
        _now = now;
        _confirm = confirm;
        _confirmationError = confirmationError;
        _snooze = snooze;

        Text = kind == ShiftConfirmationKind.ClockIn ? "Confirm clock-in" : "Confirm clock-out";
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = true;
        ShowInTaskbar = true;
        TopMost = true;
        AutoScaleMode = AutoScaleMode.Dpi;
        Width = 560;
        Height = 340;
        MinimumSize = new Size(520, 330);
        BackColor = Color.FromArgb(246, 250, 248);
        Font = new Font("Segoe UI", 10F);
        Icon = TrayIconFactory.Create();

        var accent = new Panel
        {
            Dock = DockStyle.Top,
            Height = 8,
            BackColor = kind == ShiftConfirmationKind.ClockIn
                ? Color.FromArgb(8, 127, 105)
                : Color.FromArgb(20, 82, 70)
        };
        Controls.Add(accent);

        var content = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(28, 22, 28, 22),
            ColumnCount = 1,
            RowCount = 5
        };
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        content.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        content.RowStyles.Add(new RowStyle(SizeType.AutoSize));
        Controls.Add(content);

        var eyebrow = new Label
        {
            AutoSize = true,
            ForeColor = Color.FromArgb(8, 127, 105),
            Font = new Font("Segoe UI", 9F, FontStyle.Bold),
            Text = kind == ShiftConfirmationKind.ClockIn ? "SHIFT START" : "SHIFT END",
            Margin = new Padding(0, 0, 0, 6)
        };
        content.Controls.Add(eyebrow);

        var title = new Label
        {
            AutoSize = true,
            MaximumSize = new Size(480, 0),
            ForeColor = Color.FromArgb(19, 40, 35),
            Font = new Font("Segoe UI", 17F, FontStyle.Bold),
            Text = kind == ShiftConfirmationKind.ClockIn
                ? $"Ready to start, {employeeName}?"
                : $"Ready to finish, {employeeName}?",
            Margin = new Padding(0, 0, 0, 12)
        };
        content.Controls.Add(title);

        _statusLabel = new Label
        {
            AutoSize = true,
            MaximumSize = new Size(480, 0),
            ForeColor = Color.FromArgb(81, 101, 94),
            Font = new Font("Segoe UI", 10F),
            Margin = new Padding(0, 0, 0, 18)
        };
        content.Controls.Add(_statusLabel);

        var actions = new FlowLayoutPanel
        {
            AutoSize = true,
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.LeftToRight,
            WrapContents = true,
            Margin = new Padding(0)
        };
        content.Controls.Add(actions);

        _confirmButton = new Button
        {
            AutoSize = true,
            MinimumSize = new Size(160, 44),
            BackColor = Color.FromArgb(8, 127, 105),
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat,
            Font = new Font("Segoe UI", 10F, FontStyle.Bold),
            Text = kind == ShiftConfirmationKind.ClockIn ? "Confirm clock-in" : "Clock out now",
            Margin = new Padding(0, 0, 10, 0)
        };
        _confirmButton.FlatAppearance.BorderSize = 0;
        _confirmButton.Click += async (_, _) => await ConfirmAsync();
        actions.Controls.Add(_confirmButton);

        var snoozeButton = new Button
        {
            AutoSize = true,
            MinimumSize = new Size(150, 44),
            BackColor = Color.White,
            ForeColor = Color.FromArgb(27, 77, 64),
            FlatStyle = FlatStyle.Flat,
            Font = new Font("Segoe UI", 9F, FontStyle.Bold),
            Text = kind == ShiftConfirmationKind.ClockIn ? "Remind me in 2 min" : "Still working · 5 min",
            Margin = new Padding(0)
        };
        snoozeButton.FlatAppearance.BorderColor = Color.FromArgb(199, 220, 212);
        snoozeButton.Click += (_, _) => SnoozeAndClose();
        actions.Controls.Add(snoozeButton);

        var evidence = new Label
        {
            AutoSize = true,
            MaximumSize = new Size(480, 0),
            ForeColor = Color.FromArgb(122, 139, 133),
            Font = new Font("Segoe UI", 8F),
            Text = "Your confirmation is recorded with server time and device evidence.",
            Margin = new Padding(0, 14, 0, 0)
        };
        content.Controls.Add(evidence);

        _timer = new System.Windows.Forms.Timer { Interval = 1000 };
        _timer.Tick += (_, _) => UpdateStatus();
        FormClosing += OnFormClosing;
        Shown += (_, _) =>
        {
            WindowState = FormWindowState.Normal;
            BringToFront();
            Activate();
        };
        UpdateStatus();
        _timer.Start();
    }

    private async Task ConfirmAsync()
    {
        if (_submitting) return;
        _submitting = true;
        _confirmButton.Enabled = false;
        _confirmButton.Text = "Recording...";
        try
        {
            if (await _confirm())
            {
                _completed = true;
                Close();
                return;
            }
            var detail = _confirmationError().Trim();
            _statusLabel.Text = string.IsNullOrWhiteSpace(detail)
                ? "The server could not record the confirmation. Check the connection and try again."
                : $"The server could not record the confirmation: {detail}";
            _statusLabel.ForeColor = Color.FromArgb(180, 63, 54);
        }
        finally
        {
            _submitting = false;
            _confirmButton.Enabled = true;
            _confirmButton.Text = _kind == ShiftConfirmationKind.ClockIn ? "Try clock-in again" : "Try clock-out again";
        }
    }

    private void UpdateStatus()
    {
        if (_submitting) return;
        var now = _now();
        if (_kind == ShiftConfirmationKind.ClockIn)
        {
            var graceEnd = _shiftMoment.AddMinutes(_graceMinutes);
            if (now < _shiftMoment)
            {
                _statusLabel.Text = $"Your shift starts at {_shiftMoment:HH:mm}. Confirm now to be recorded on time; payable time will not begin before the shift.";
                _statusLabel.ForeColor = Color.FromArgb(81, 101, 94);
            }
            else if (now <= graceEnd)
            {
                _statusLabel.Text = $"You are inside the {_graceMinutes}-minute on-time window, which ends at {graceEnd:HH:mm}.";
                _statusLabel.ForeColor = Color.FromArgb(20, 117, 82);
            }
            else
            {
                var lateMinutes = Math.Max(1, (int)Math.Floor((now - _shiftMoment).TotalMinutes));
                _statusLabel.Text = $"The on-time window has passed. Confirming now will record arrival as {lateMinutes} minutes late.";
                _statusLabel.ForeColor = Color.FromArgb(180, 92, 34);
            }
        }
        else
        {
            if (now < _shiftMoment)
            {
                _statusLabel.Text = $"Your shift ends at {_shiftMoment:HH:mm}. Clock out now, or choose Still working for another reminder.";
                _statusLabel.ForeColor = Color.FromArgb(81, 101, 94);
            }
            else
            {
                var overdueMinutes = Math.Max(0, (int)Math.Floor((now - _shiftMoment).TotalMinutes));
                _statusLabel.Text = overdueMinutes == 0
                    ? "Your scheduled shift has ended. Confirm your clock-out now."
                    : $"Clock-out is {overdueMinutes} minutes overdue. Confirm now or ask an administrator to correct the record.";
                _statusLabel.ForeColor = overdueMinutes > 0 ? Color.FromArgb(180, 92, 34) : Color.FromArgb(20, 117, 82);
            }
        }
    }

    private void SnoozeAndClose()
    {
        _completed = true;
        _snooze();
        Close();
    }

    private void OnFormClosing(object? sender, FormClosingEventArgs e)
    {
        if (!_completed && !_submitting)
        {
            _completed = true;
            _snooze();
        }
        _timer.Stop();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing) _timer.Dispose();
        base.Dispose(disposing);
    }
}
