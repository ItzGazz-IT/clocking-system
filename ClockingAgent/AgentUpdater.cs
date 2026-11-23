using System.Diagnostics;
using System.Net.Http.Json;
using System.Reflection;
using System.Security.Cryptography;
using System.Text.Json.Serialization;

public sealed class AgentUpdater
{
    private readonly HttpClient _http;
    private readonly Func<AgentOptions> _options;

    public AgentUpdater(HttpClient http, AgentOptions options)
        : this(http, () => options)
    {
    }

    private AgentUpdater(HttpClient http, Func<AgentOptions> options)
    {
        _http = http;
        _options = options;
    }

    public async Task<UpdateCheckResult> CheckAndInstallAsync(CancellationToken cancellationToken)
    {
        var options = _options();
        if (!options.IsConfigured)
        {
            return UpdateCheckResult.Skipped("Agent is not configured.");
        }

        using var request = new HttpRequestMessage(HttpMethod.Get, WindowsEventSender.BuildUrl(options, "/api/agent-update"));
        request.Headers.Add("Authorization", options.DeviceToken);

        using var response = await _http.SendAsync(request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            return UpdateCheckResult.Skipped($"Update check failed: {(int)response.StatusCode} {response.StatusCode}");
        }

        var manifest = await response.Content.ReadFromJsonAsync<UpdateManifestResponse>(cancellationToken);
        var update = manifest?.Data;
        if (update is null || string.IsNullOrWhiteSpace(update.Version)
            || string.IsNullOrWhiteSpace(update.DownloadUrl) || !IsSha256(update.Sha256))
        {
            return UpdateCheckResult.Skipped("Update manifest was empty.");
        }

        if (!IsNewer(update.Version, CurrentVersion()))
        {
            return UpdateCheckResult.Skipped("Agent is current.");
        }

        var installerPath = Path.Combine(Path.GetTempPath(), $"Time-Attendance-Agent-Setup-{update.Version}.exe");
        using (var installer = await _http.GetStreamAsync(update.DownloadUrl, cancellationToken))
        await using (var file = File.Create(installerPath))
        {
            await installer.CopyToAsync(file, cancellationToken);
        }

        string actualHash;
        await using (var downloadedFile = File.OpenRead(installerPath))
        {
            actualHash = Convert.ToHexString(await SHA256.HashDataAsync(downloadedFile, cancellationToken));
        }
        if (!actualHash.Equals(update.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            File.Delete(installerPath);
            return UpdateCheckResult.Skipped("Downloaded update failed its security check.");
        }

        Process.Start(new ProcessStartInfo
        {
            FileName = installerPath,
            Arguments = "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /CLOSEAPPLICATIONS /FORCECLOSEAPPLICATIONS /RESTARTAPPLICATIONS",
            UseShellExecute = true
        });

        return UpdateCheckResult.FromInstalling(update.Version);
    }

    private static bool IsNewer(string candidate, string current)
    {
        return Version.TryParse(candidate, out var candidateVersion)
            && Version.TryParse(current, out var currentVersion)
            && candidateVersion > currentVersion;
    }

    private static bool IsSha256(string? value)
        => value is { Length: 64 } && value.All(Uri.IsHexDigit);

    public static string CurrentVersion()
        => Assembly.GetExecutingAssembly()
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?
            .InformationalVersion
            .Split('+')[0] ?? "1.0.4";
}

public sealed record UpdateCheckResult(bool IsInstalling, string Message)
{
    public static UpdateCheckResult FromInstalling(string version)
        => new(true, $"Installing update {version}.");

    public static UpdateCheckResult Skipped(string message)
        => new(false, message);
}

public sealed record UpdateManifestResponse(
    [property: JsonPropertyName("data")] UpdateManifest? Data
);

public sealed record UpdateManifest(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("download_url")] string DownloadUrl,
    [property: JsonPropertyName("sha256")] string? Sha256,
    [property: JsonPropertyName("required")] bool Required
);
