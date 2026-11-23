using System.Net.Http.Json;
using System.Reflection;
using System.Text.Json;

public sealed class WindowsEventSender
{
    private readonly HttpClient _http;
    private readonly Func<AgentOptions> _options;

    public WindowsEventSender(HttpClient http, AgentOptions options)
        : this(http, () => options)
    {
    }

    private WindowsEventSender(HttpClient http, Func<AgentOptions> options)
    {
        _http = http;
        _options = options;
    }

    public async Task<SendResult> SendIdleEventAsync(int idleSeconds, CancellationToken cancellationToken)
        => await SendWindowsEventAsync("idle", idleSeconds, cancellationToken);

    public async Task<SendResult> SendActivitySliceAsync(ActivitySlice slice, CancellationToken cancellationToken)
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return SendResult.Failure("Agent is not configured.");
        }

        var payload = new
        {
            idempotency_key = Guid.NewGuid().ToString(),
            usage_type = slice.UsageType,
            app_name = slice.AppName,
            window_title = slice.WindowTitle,
            website_domain = slice.WebsiteDomain,
            duration_seconds = slice.DurationSeconds,
            started_at = slice.StartedAt.ToString("o"),
            ended_at = slice.EndedAt.ToString("o"),
            metadata = BuildMetadata(null)
        };

        using var request = CreateRequest(options, "/api/activity", payload);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            var message = $"Activity {slice.AppName} {slice.DurationSeconds}s -> {(int)response.StatusCode} {response.StatusCode}";

            if (!response.IsSuccessStatusCode && !string.IsNullOrWhiteSpace(body))
            {
                message = $"{message}: {body}";
            }

            Console.WriteLine($"{DateTime.Now}: {message}");
            return new SendResult(response.IsSuccessStatusCode, message, slice.DurationSeconds, DateTimeOffset.Now);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Console.WriteLine($"{DateTime.Now}: Activity send failed: {ex.Message}");
            return SendResult.Failure(ex.Message, slice.DurationSeconds);
        }
    }

    public async Task<SendResult> SendWindowsEventAsync(
        string eventCode,
        int? idleSeconds,
        CancellationToken cancellationToken,
        IReadOnlyDictionary<string, string>? extraMetadata = null
    )
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return SendResult.Failure("Agent is not configured.");
        }

        var payload = new
        {
            @event = eventCode,
            occurred_at = DateTimeOffset.UtcNow.ToString("o"),
            idempotency_key = Guid.NewGuid().ToString(),
            idle_seconds = idleSeconds,
            metadata = BuildMetadata(extraMetadata)
        };

        using var request = CreateRequest(options, "/api/windows-event", payload);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            var details = idleSeconds is null ? eventCode : $"{eventCode} {idleSeconds}s";
            var message = $"Windows {details} -> {(int)response.StatusCode} {response.StatusCode}";

            if (!response.IsSuccessStatusCode && !string.IsNullOrWhiteSpace(body))
            {
                message = $"{message}: {body}";
            }

            Console.WriteLine($"{DateTime.Now}: {message}");
            return new SendResult(response.IsSuccessStatusCode, message, idleSeconds ?? 0, DateTimeOffset.Now);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Console.WriteLine($"{DateTime.Now}: Send failed: {ex.Message}");
            return SendResult.Failure(ex.Message, idleSeconds ?? 0);
        }
    }

    public async Task<SendResult> SendAttendanceActionAsync(
        AttendanceAction action,
        CancellationToken cancellationToken,
        string confirmationSource = "tray_menu"
    )
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return SendResult.Failure("Agent is not configured.");
        }

        var payload = new
        {
            idempotency_key = Guid.NewGuid().ToString(),
            computer_name = Environment.MachineName,
            confirmation_source = confirmationSource
        };

        using var request = CreateRequest(options, action.Path, payload);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            var message = $"{action.Label} -> {(int)response.StatusCode} {response.StatusCode}";

            if (!response.IsSuccessStatusCode && !string.IsNullOrWhiteSpace(body))
            {
                message = $"{message}: {body}";
            }

            Console.WriteLine($"{DateTime.Now}: {message}");
            return new SendResult(response.IsSuccessStatusCode, message, 0, DateTimeOffset.Now);
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Console.WriteLine($"{DateTime.Now}: {action.Label} failed: {ex.Message}");
            return SendResult.Failure(ex.Message);
        }
    }

    public async Task<SendResult> CheckEmployeeAsync(CancellationToken cancellationToken)
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return SendResult.Failure("Agent is not configured.");
        }

        using var request = new HttpRequestMessage(HttpMethod.Get, BuildUrl(options, "/api/employee"));
        request.Headers.Add("Authorization", options.DeviceToken);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            var message = response.IsSuccessStatusCode
                ? "Device linked to employee"
                : $"Employee check -> {(int)response.StatusCode} {response.StatusCode}: {body}";

            return new SendResult(response.IsSuccessStatusCode, message, 0, DateTimeOffset.Now);
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            return SendResult.Failure(ex.Message);
        }
    }

    public async Task<EmployeeScheduleResult> GetEmployeeScheduleAsync(CancellationToken cancellationToken)
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return EmployeeScheduleResult.Failure("Agent is not configured.");
        }

        using var request = new HttpRequestMessage(HttpMethod.Get, BuildUrl(options, "/api/employee"));
        request.Headers.Add("Authorization", options.DeviceToken);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                return EmployeeScheduleResult.Failure(
                    $"Employee schedule -> {(int)response.StatusCode} {response.StatusCode}: {body}"
                );
            }

            using var document = JsonDocument.Parse(body);
            var data = document.RootElement.GetProperty("data");
            var start = data.TryGetProperty("start_time", out var startElement)
                ? startElement.GetString()
                : null;
            var end = data.TryGetProperty("end_time", out var endElement)
                ? endElement.GetString()
                : null;
            var employee = data.TryGetProperty("first_name", out var firstElement)
                && data.TryGetProperty("last_name", out var lastElement)
                    ? $"{firstElement.GetString()} {lastElement.GetString()}".Trim()
                    : "Employee";
            var spansMidnight = data.TryGetProperty("spans_midnight", out var spansElement)
                && (spansElement.ValueKind switch
                {
                    JsonValueKind.True => true,
                    JsonValueKind.Number => spansElement.GetInt32() == 1,
                    JsonValueKind.String => spansElement.GetString() is "1" or "true",
                    _ => false
                });
            var scheduledDays = data.TryGetProperty("days_of_week", out var daysElement)
                ? ParseScheduledDays(daysElement)
                : EmployeeScheduleResult.DefaultWeekdays;
            var timezone = data.TryGetProperty("timezone", out var timezoneElement)
                ? timezoneElement.GetString()
                : null;
            var graceMinutes = JsonInteger(data, "grace_period_minutes", 0);
            var absenceMinutes = JsonInteger(data, "absence_after_minutes", 60);
            var missingClockOutMinutes = JsonInteger(data, "missing_clock_out_after_minutes", 30);
            var isFlexible = data.TryGetProperty("attendance_mode", out var modeElement)
                && modeElement.GetString() == "flexible";

            return TimeOnly.TryParse(start, out var startTime) && TimeOnly.TryParse(end, out var endTime)
                ? EmployeeScheduleResult.FromSchedule(employee, startTime, endTime, spansMidnight, scheduledDays, timezone,
                    graceMinutes, absenceMinutes, missingClockOutMinutes, isFlexible)
                : EmployeeScheduleResult.Failure("No shift start/end time is assigned.");
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            return EmployeeScheduleResult.Failure(ex.Message);
        }
    }

    private static IReadOnlySet<int> ParseScheduledDays(JsonElement element)
    {
        if (element.ValueKind == JsonValueKind.String)
        {
            var json = element.GetString();
            if (string.IsNullOrWhiteSpace(json)) return EmployeeScheduleResult.DefaultWeekdays;
            using var document = JsonDocument.Parse(json);
            return ParseScheduledDays(document.RootElement);
        }

        if (element.ValueKind != JsonValueKind.Array) return EmployeeScheduleResult.DefaultWeekdays;
        var days = element.EnumerateArray()
            .Where(day => day.TryGetInt32(out var value) && value is >= 1 and <= 7)
            .Select(day => day.GetInt32())
            .ToHashSet();
        return days;
    }

    private static int JsonInteger(JsonElement data, string property, int fallback)
    {
        if (!data.TryGetProperty(property, out var element)) return fallback;
        if (element.ValueKind == JsonValueKind.Number && element.TryGetInt32(out var number)) return number;
        if (element.ValueKind == JsonValueKind.String && int.TryParse(element.GetString(), out number)) return number;
        return fallback;
    }

    public async Task<AttendanceStatusResult> GetAttendanceStatusAsync(CancellationToken cancellationToken)
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return AttendanceStatusResult.Failure("Agent is not configured.");
        }

        using var request = new HttpRequestMessage(HttpMethod.Get, BuildUrl(options, "/api/attendance"));
        request.Headers.Add("Authorization", options.DeviceToken);

        try
        {
            var response = await _http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                return AttendanceStatusResult.Failure(
                    $"Attendance status -> {(int)response.StatusCode} {response.StatusCode}: {body}"
                );
            }

            var current = ParseCurrentStatus(body);
            return AttendanceStatusResult.FromState(current.State, current.IsOvertime);
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            return AttendanceStatusResult.Failure(ex.Message);
        }
    }

    public static async Task<DeviceEnrollmentResult> EnrollDeviceAsync(
        HttpClient http,
        string apiBaseUrl,
        string username,
        string password,
        CancellationToken cancellationToken
    )
    {
        if (!Uri.TryCreate(apiBaseUrl, UriKind.Absolute, out _))
        {
            return DeviceEnrollmentResult.Failure("Enter a valid API URL.");
        }

        var payload = new
        {
            username,
            password,
            hostname = Environment.MachineName,
            operating_system = Environment.OSVersion.VersionString,
            device_uuid = DeviceIdentity.GetOrCreate(),
            agent_version = AgentVersion()
        };

        using var request = new HttpRequestMessage(HttpMethod.Post, $"{apiBaseUrl.TrimEnd('/')}/api/device/enroll")
        {
            Content = JsonContent.Create(payload)
        };
        request.Headers.Accept.ParseAdd("application/json");

        try
        {
            var response = await http.SendAsync(request, cancellationToken);
            var body = await response.Content.ReadAsStringAsync(cancellationToken);
            if (!response.IsSuccessStatusCode)
            {
                return DeviceEnrollmentResult.Failure(
                    $"Device link failed: {(int)response.StatusCode} {response.StatusCode}: {body}"
                );
            }

            using var document = JsonDocument.Parse(body);
            var token = document.RootElement
                .GetProperty("data")
                .GetProperty("token")
                .GetString();

            return string.IsNullOrWhiteSpace(token)
                ? DeviceEnrollmentResult.Failure("The server did not return a device token.")
                : DeviceEnrollmentResult.FromToken(token);
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            return DeviceEnrollmentResult.Failure(ex.Message);
        }
    }

    private static (AttendanceState State, bool IsOvertime) ParseCurrentStatus(string body)
    {
        using var document = JsonDocument.Parse(body);
        if (!document.RootElement.TryGetProperty("data", out var data) || data.ValueKind != JsonValueKind.Array)
        {
            return (AttendanceState.Unknown, false);
        }

        foreach (var item in data.EnumerateArray())
        {
            if (!item.TryGetProperty("status", out var statusElement))
            {
                continue;
            }

            var status = statusElement.GetString();
            var latestEvent = item.TryGetProperty("latest_event_code", out var latestEventElement)
                ? latestEventElement.GetString()
                : null;
            var isOvertime = item.TryGetProperty("session_type", out var sessionTypeElement)
                && sessionTypeElement.GetString() == "overtime";
            if (status == "missing_clock_out")
            {
                var state = latestEvent switch
                {
                    "break_start" => AttendanceState.OnBreak,
                    "lunch_start" => AttendanceState.OnLunch,
                    _ => AttendanceState.MissingClockOut
                };
                return (state, isOvertime);
            }
            var currentState = status switch
            {
                "working" => AttendanceState.Working,
                "break" => AttendanceState.OnBreak,
                "lunch" => AttendanceState.OnLunch,
                "closed" => AttendanceState.NotClockedIn,
                null or "" => AttendanceState.NotClockedIn,
                _ => AttendanceState.Unknown
            };
            return (currentState, isOvertime);
        }

        return (AttendanceState.NotClockedIn, false);
    }

    private static HttpRequestMessage CreateRequest(AgentOptions options, string path, object payload)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, BuildUrl(options, path));
        request.Headers.Add("Authorization", options.DeviceToken);
        request.Content = JsonContent.Create(payload);
        return request;
    }

    public static string BuildUrl(AgentOptions options, string path)
    {
        return $"{options.ApiBaseUrl.TrimEnd('/')}/{path.TrimStart('/')}";
    }

    private static Dictionary<string, string> BuildMetadata(IReadOnlyDictionary<string, string>? extraMetadata)
    {
        var metadata = new Dictionary<string, string>
        {
            ["hostname"] = Environment.MachineName,
            ["source"] = "clocking_agent",
            ["version"] = AgentVersion()
        };

        if (extraMetadata is null)
        {
            return metadata;
        }

        foreach (var item in extraMetadata)
        {
            metadata[item.Key] = item.Value;
        }

        return metadata;
    }

    private static string AgentVersion()
        => AgentUpdater.CurrentVersion();
}

public sealed record SendResult(bool Success, string Message, int IdleSeconds, DateTimeOffset OccurredAt)
{
    public static SendResult Failure(string message, int idleSeconds = 0)
        => new(false, message, idleSeconds, DateTimeOffset.Now);
}

public sealed record AttendanceAction(string Label, string Path)
{
    public static readonly AttendanceAction ClockIn = new("Clock in", "/api/clockin");
    public static readonly AttendanceAction ClockOut = new("Clock out", "/api/clockout");
    public static readonly AttendanceAction StartBreak = new("Start break", "/api/break/start");
    public static readonly AttendanceAction EndBreak = new("End break", "/api/break/end");
    public static readonly AttendanceAction StartLunch = new("Start lunch", "/api/lunch/start");
    public static readonly AttendanceAction EndLunch = new("End lunch", "/api/lunch/end");
    public static readonly AttendanceAction StartOvertime = new("Start overtime", "/api/overtime/start");
    public static readonly AttendanceAction EndOvertime = new("End overtime", "/api/overtime/end");
}

public enum AttendanceState
{
    Unknown,
    NotClockedIn,
    Working,
    OnBreak,
    OnLunch,
    MissingClockOut
}

public sealed record AttendanceStatusResult(bool Success, AttendanceState State, bool IsOvertime, string Message)
{
    public static AttendanceStatusResult FromState(AttendanceState state, bool isOvertime = false)
        => new(true, state, isOvertime, "");

    public static AttendanceStatusResult Failure(string message)
        => new(false, AttendanceState.Unknown, false, message);
}

public sealed record DeviceEnrollmentResult(bool Success, string Token, string Message)
{
    public static DeviceEnrollmentResult FromToken(string token)
        => new(true, token, "");

    public static DeviceEnrollmentResult Failure(string message)
        => new(false, "", message);
}

public sealed record EmployeeScheduleResult(
    bool Success,
    string EmployeeName,
    TimeOnly StartTime,
    TimeOnly EndTime,
    bool SpansMidnight,
    IReadOnlySet<int> ScheduledDays,
    string Timezone,
    int GracePeriodMinutes,
    int AbsenceAfterMinutes,
    int MissingClockOutAfterMinutes,
    bool IsFlexible,
    string Message
)
{
    public static IReadOnlySet<int> DefaultWeekdays { get; } = new HashSet<int> { 1, 2, 3, 4, 5 };

    public static EmployeeScheduleResult FromSchedule(
        string employeeName,
        TimeOnly startTime,
        TimeOnly endTime,
        bool spansMidnight,
        IReadOnlySet<int> scheduledDays,
        string? timezone,
        int gracePeriodMinutes,
        int absenceAfterMinutes,
        int missingClockOutAfterMinutes,
        bool isFlexible
    )
        => new(true, employeeName, startTime, endTime, spansMidnight, scheduledDays,
            string.IsNullOrWhiteSpace(timezone) ? "Africa/Johannesburg" : timezone,
            Math.Max(0, gracePeriodMinutes), Math.Max(0, absenceAfterMinutes), Math.Max(0, missingClockOutAfterMinutes), isFlexible, "");

    public static EmployeeScheduleResult Failure(string message)
        => new(false, "", default, default, false, DefaultWeekdays, "Africa/Johannesburg", 0, 60, 30, false, message);
}
