namespace LinguaCafe.PcTest;

public sealed class PcTestState
{
    public string AdminEmail { get; set; } = "pc-test-admin@local.invalid";
    public string AdminPassword { get; set; } = string.Empty;
    public bool AdminProvisioned { get; set; }
}
