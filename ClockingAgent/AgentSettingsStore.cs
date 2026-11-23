using System.Text.Json;
using System.Text.Json.Nodes;

public static class AgentSettingsStore
{
    private static readonly JsonSerializerOptions WriteOptions = new()
    {
        WriteIndented = true
    };

    public static string SettingsDirectory => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "Time Attendance Tracking",
        "Agent"
    );

    public static string SettingsPath => Path.Combine(SettingsDirectory, "appsettings.json");

    public static void Save(AgentOptions options)
    {
        Directory.CreateDirectory(SettingsDirectory);

        JsonObject root;
        if (File.Exists(SettingsPath))
        {
            var existing = JsonNode.Parse(File.ReadAllText(SettingsPath)) as JsonObject;
            root = existing ?? [];
        }
        else
        {
            root = [];
        }

        root["Agent"] = new JsonObject
        {
            ["ApiBaseUrl"] = options.ApiBaseUrl.Trim(),
            ["DeviceToken"] = NormalizeToken(options.DeviceToken),
            ["IntervalSeconds"] = Math.Max(5, options.IntervalSeconds)
            ,["ControlPasswordHash"] = options.ControlPasswordHash
        };

        File.WriteAllText(SettingsPath, root.ToJsonString(WriteOptions));
    }

    public static string NormalizeToken(string token)
    {
        token = token.Trim();
        if (token.Length == 0 || token.StartsWith("Bearer ", StringComparison.OrdinalIgnoreCase))
        {
            return token;
        }

        return $"Bearer {token}";
    }
}
