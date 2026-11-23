using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using FlaUI.Core.Definitions;
using FlaUI.UIA3;

public sealed class ActivityTracker
{
    private const int MaxTitleLength = 220;
    private ActivitySnapshot? _lastSnapshot;
    private DateTimeOffset _lastObservedAt = DateTimeOffset.UtcNow;

    public ActivitySlice? Observe(bool isIdle)
    {
        var now = DateTimeOffset.UtcNow;
        var current = isIdle ? null : ActivitySnapshot.Current();
        var previous = _lastSnapshot;
        var previousAt = _lastObservedAt;

        _lastSnapshot = current;
        _lastObservedAt = now;

        if (previous is null || previousAt >= now)
        {
            return null;
        }

        var durationSeconds = (int)Math.Round((now - previousAt).TotalSeconds);
        if (durationSeconds <= 0)
        {
            return null;
        }

        var domain = previous.WebsiteDomain;

        return new ActivitySlice(
            domain is null ? "app" : "website",
            previous.ProcessName,
            previous.WindowTitle,
            domain,
            durationSeconds,
            previousAt,
            now
        );
    }

    private sealed record ActivitySnapshot(string ProcessName, string WindowTitle, string? WebsiteDomain)
    {
        public static ActivitySnapshot? Current()
        {
            var handle = GetForegroundWindow();
            if (handle == IntPtr.Zero)
            {
                return null;
            }

            GetWindowThreadProcessId(handle, out var processId);
            if (processId == 0)
            {
                return null;
            }

            var title = ReadWindowTitle(handle);
            try
            {
                using var process = Process.GetProcessById((int)processId);
                var processName = string.IsNullOrWhiteSpace(process.ProcessName)
                    ? "Unknown"
                    : process.ProcessName;

                return new ActivitySnapshot(processName, title, BrowserUrlReader.TryGetDomain(handle, processName));
            }
            catch
            {
                return new ActivitySnapshot("Unknown", title, null);
            }
        }

        private static string ReadWindowTitle(IntPtr handle)
        {
            var builder = new StringBuilder(MaxTitleLength + 1);
            _ = GetWindowText(handle, builder, builder.Capacity);
            var title = builder.ToString().Trim();
            return title.Length > MaxTitleLength ? title[..MaxTitleLength] : title;
        }

        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll")]
        private static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);

        [DllImport("user32.dll")]
        private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId);
    }
}

public static class BrowserUrlReader
{
    private static readonly HashSet<string> BrowserProcesses = new(StringComparer.OrdinalIgnoreCase)
    {
        "chrome",
        "msedge",
        "firefox",
        "brave",
        "opera",
        "vivaldi"
    };

    public static string? TryGetDomain(IntPtr windowHandle, string processName)
    {
        if (!BrowserProcesses.Contains(processName))
        {
            return null;
        }

        try
        {
            using var automation = new UIA3Automation();
            var root = automation.FromHandle(windowHandle);
            var edits = root.FindAllDescendants(condition => condition.ByControlType(ControlType.Edit));
            foreach (var element in edits)
            {
                if (!element.Patterns.Value.IsSupported)
                {
                    continue;
                }

                var value = element.Patterns.Value.Pattern.Value.Value?.Trim();
                var domain = NormalizeDomain(value);
                if (domain is not null)
                {
                    return domain;
                }
            }
        }
        catch
        {
            return null;
        }

        return null;
    }

    private static string? NormalizeDomain(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        value = value.Trim();
        if (!value.Contains('.') || value.Contains(' '))
        {
            return null;
        }

        if (!value.Contains("://"))
        {
            value = "https://" + value;
        }

        if (!Uri.TryCreate(value, UriKind.Absolute, out var uri) || string.IsNullOrWhiteSpace(uri.Host))
        {
            return null;
        }

        var host = uri.Host.StartsWith("www.", StringComparison.OrdinalIgnoreCase)
            ? uri.Host[4..]
            : uri.Host;

        return host.Length > 190 ? host[..190] : host.ToLowerInvariant();
    }
}

public sealed record ActivitySlice(
    string UsageType,
    string AppName,
    string WindowTitle,
    string? WebsiteDomain,
    int DurationSeconds,
    DateTimeOffset StartedAt,
    DateTimeOffset EndedAt
);
