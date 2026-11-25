using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace LinguaCafe.PcTest;

/// <summary>
/// Stores only Windows-current-user DPAPI ciphertext on disk. The password is
/// available to the existing setup/login code in memory, never in state.json.
/// This store does not access any real state path on its own.
/// </summary>
internal static class PcTestStatePersistence
{
    private static readonly byte[] Purpose = Encoding.UTF8.GetBytes(
        "LinguaCafe.PcTest.StudyV4.AdminPassword.v1");

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
    };

    internal static PcTestState Load(string path)
    {
        EnsureWindows();
        var raw = File.ReadAllText(path, Encoding.UTF8);
        var state = JsonSerializer.Deserialize<PcTestState>(raw, JsonOptions)
            ?? throw new InvalidOperationException("PC test state is invalid.");
        if (state.SchemaVersion is not (1 or 2))
        {
            throw new InvalidOperationException("Unsupported PC test state schema version.");
        }

        if (!string.IsNullOrWhiteSpace(state.AdminPasswordProtected))
        {
            if (state.SchemaVersion != 2)
            {
                throw new InvalidOperationException("Protected PC test state must be schema v2.");
            }
            try
            {
                var ciphertext = Convert.FromBase64String(state.AdminPasswordProtected);
                var passwordBytes = ProtectedData.Unprotect(
                    ciphertext, Purpose, DataProtectionScope.CurrentUser);
                try
                {
                    state.AdminPassword = Encoding.UTF8.GetString(passwordBytes);
                }
                finally
                {
                    CryptographicOperations.ZeroMemory(passwordBytes);
                }
            }
            catch (Exception exception) when (
                exception is CryptographicException or FormatException)
            {
                // Never silently create a different admin or downgrade to plaintext.
                throw new InvalidOperationException(
                    "PC test credentials cannot be unlocked for this Windows user; "
                    + "preserve the original state and verify the account or backup.");
            }

            if (string.IsNullOrEmpty(state.AdminPassword))
            {
                throw new InvalidOperationException("PC test stored credentials are empty.");
            }

            return state;
        }

        // Legacy releases used a plaintext JSON property. Read it only from
        // the current, explicitly supplied state file and atomically upgrade.
        using var document = JsonDocument.Parse(raw);
        if (document.RootElement.TryGetProperty("SchemaVersion", out var legacySchema)
            && (legacySchema.ValueKind != JsonValueKind.Number
                || legacySchema.GetInt32() != 1))
        {
            throw new InvalidOperationException("Protected PC test state cannot downgrade to plaintext.");
        }
        if (!document.RootElement.TryGetProperty("AdminPassword", out var legacy)
            || legacy.ValueKind != JsonValueKind.String)
        {
            throw new InvalidOperationException(
                "PC test state has no protected credentials; refusing to create a new admin.");
        }

        state.AdminPassword = legacy.GetString() ?? string.Empty;
        if (string.IsNullOrEmpty(state.AdminPassword))
        {
            throw new InvalidOperationException("PC test legacy credentials are empty.");
        }

        Save(path, state);
        return state;
    }

    internal static void Save(string path, PcTestState state)
    {
        EnsureWindows();
        if (string.IsNullOrEmpty(state.AdminPassword))
        {
            throw new InvalidOperationException(
                "PC test credentials are empty; refusing to overwrite saved state.");
        }

        if (state.SchemaVersion is not (1 or 2))
        {
            throw new InvalidOperationException("Unsupported PC test state schema version.");
        }
        state.SchemaVersion = 2;
        var passwordBytes = Encoding.UTF8.GetBytes(state.AdminPassword);
        try
        {
            var ciphertext = ProtectedData.Protect(
                passwordBytes, Purpose, DataProtectionScope.CurrentUser);
            state.AdminPasswordProtected = Convert.ToBase64String(ciphertext);
            CryptographicOperations.ZeroMemory(ciphertext);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(passwordBytes);
        }

        var serialized = JsonSerializer.Serialize(state, JsonOptions);
        var folder = Path.GetDirectoryName(Path.GetFullPath(path))
            ?? throw new InvalidOperationException("Invalid PC test state location.");
        Directory.CreateDirectory(folder);

        // Staging is only ever ciphertext. Replace is atomic on the same
        // Windows volume and produces no plaintext rollback backup.
        var staging = Path.Combine(folder, ".pc-test-state-" + Guid.NewGuid().ToString("N") + ".tmp");
        try
        {
            File.WriteAllText(staging, serialized, Encoding.UTF8);
            if (File.Exists(path))
            {
                File.Replace(staging, path, null);
            }
            else
            {
                File.Move(staging, path);
            }
        }
        finally
        {
            if (File.Exists(staging))
            {
                File.Delete(staging);
            }
        }
    }

    private static void EnsureWindows()
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException(
                "PC test credential protection requires the Windows current-user profile.");
        }
    }
}
