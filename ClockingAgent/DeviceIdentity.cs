public static class DeviceIdentity
{
    private static readonly string DirectoryPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
        "Time Attendance Tracking",
        "Agent"
    );

    private static readonly string FilePath = Path.Combine(DirectoryPath, "device.id");

    public static string GetOrCreate()
    {
        if (File.Exists(FilePath))
        {
            var existing = File.ReadAllText(FilePath).Trim();
            if (existing.Length > 0)
            {
                return existing;
            }
        }

        Directory.CreateDirectory(DirectoryPath);
        var value = Guid.NewGuid().ToString("D");
        File.WriteAllText(FilePath, value);
        return value;
    }
}
