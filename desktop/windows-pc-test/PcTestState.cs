using System.Text.Json.Serialization;

namespace LinguaCafe.PcTest;

public sealed class PcTestState
{
    public int SchemaVersion { get; set; } = 2;
    public string AdminEmail { get; set; } = "pc-test-admin@local.invalid";
    // Used only in memory for the existing canonical setup/login UI.
    [JsonIgnore]
    public string AdminPassword { get; set; } = string.Empty;
    public string AdminPasswordProtected { get; set; } = string.Empty;
    public bool AdminProvisioned { get; set; }
}
