using System.Windows.Forms;
using Microsoft.Extensions.Configuration;

try
{
    if (args.Any(static arg => arg.Equals("--report-uninstall", StringComparison.OrdinalIgnoreCase)))
    {
        await ReportLifecycleEventAsync("agent_uninstall", "installer_uninstall");
        return;
    }

    ApplicationConfiguration.Initialize();
    Application.Run(new TrayApplicationContext(AgentOptionsLoader.Load()));
}
catch (Exception ex)
{
    MessageBox.Show(
        ex.Message,
        "Clocking Agent failed to start",
        MessageBoxButtons.OK,
        MessageBoxIcon.Error
    );
}

static async Task ReportLifecycleEventAsync(string eventCode, string reason)
{
    var options = AgentOptionsLoader.Load();
    if (!options.IsConfigured)
    {
        return;
    }

    using var http = new HttpClient();
    using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(10));
    var sender = new WindowsEventSender(http, options);
    await sender.SendWindowsEventAsync(
        eventCode,
        null,
        timeout.Token,
        new Dictionary<string, string>
        {
            ["reason"] = reason,
            ["process"] = "ClockingAgent.exe"
        }
    );
}

public sealed class AgentOptions
{
    public string ApiBaseUrl { get; set; } = "";
    public string DeviceToken { get; set; } = "";
    public int IntervalSeconds { get; set; } = 60;
    public string ControlPasswordHash { get; set; } = "";

    public bool IsConfigured =>
        Uri.TryCreate(ApiBaseUrl, UriKind.Absolute, out _)
        && DeviceToken.StartsWith("Bearer ", StringComparison.OrdinalIgnoreCase)
        && !DeviceToken.Contains("replace-with-device-token", StringComparison.OrdinalIgnoreCase);

    public void Apply(AgentOptions options)
    {
        ApiBaseUrl = options.ApiBaseUrl;
        DeviceToken = options.DeviceToken;
        IntervalSeconds = Math.Max(5, options.IntervalSeconds);
        ControlPasswordHash = options.ControlPasswordHash;
    }

    public AgentOptions Copy()
        => new()
        {
            ApiBaseUrl = ApiBaseUrl,
            DeviceToken = DeviceToken,
            IntervalSeconds = IntervalSeconds
            ,ControlPasswordHash = ControlPasswordHash
        };
}

public static class AgentOptionsLoader
{
    public static AgentOptions Load()
    {
        var environment = Environment.GetEnvironmentVariable("DOTNET_ENVIRONMENT") ?? "Production";
        var configuration = new ConfigurationBuilder()
            .SetBasePath(AppContext.BaseDirectory)
            .AddJsonFile("appsettings.json", optional: true, reloadOnChange: true)
            .AddJsonFile($"appsettings.{environment}.json", optional: true, reloadOnChange: true)
            .AddJsonFile(AgentSettingsStore.SettingsPath, optional: true, reloadOnChange: true)
            .AddEnvironmentVariables("CLOCKING_")
            .Build();

        var options = configuration.GetSection("Agent").Get<AgentOptions>() ?? new AgentOptions();

        options.ApiBaseUrl = Environment.GetEnvironmentVariable("CLOCKING_API_BASE_URL") ?? options.ApiBaseUrl;
        options.DeviceToken = Environment.GetEnvironmentVariable("CLOCKING_DEVICE_TOKEN") ?? options.DeviceToken;

        if (int.TryParse(Environment.GetEnvironmentVariable("CLOCKING_INTERVAL_SECONDS"), out var intervalSeconds))
        {
            options.IntervalSeconds = intervalSeconds;
        }

        options.IntervalSeconds = Math.Max(5, options.IntervalSeconds);
        return options;
    }
}
