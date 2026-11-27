using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LinguaCafe.PcTest;

static void Must(bool condition, string message)
{
    if (!condition)
    {
        throw new InvalidOperationException("PC state synthetic smoke: " + message);
    }
}

// The startup probe must distinguish a real login document from errors,
// redirects, empty responses, and JSON on the same endpoint.
foreach (var status in new[] { HttpStatusCode.Unauthorized, HttpStatusCode.Forbidden,
    HttpStatusCode.NotFound, HttpStatusCode.InternalServerError, HttpStatusCode.Redirect,
    HttpStatusCode.NoContent })
{
    using var response = new HttpResponseMessage(status)
    {
        Content = new StringContent("synthetic", Encoding.UTF8, "text/html"),
    };
    Must(!RuntimeManager.IsLoginPageReady(response), $"HTTP {(int)status} must not pass login readiness");
}
using (var response = new HttpResponseMessage(HttpStatusCode.OK)
{
    Content = new StringContent("{}", Encoding.UTF8, "application/json"),
})
{
    Must(!RuntimeManager.IsLoginPageReady(response), "HTTP 200 JSON must not pass HTML login readiness");
}
using (var response = new HttpResponseMessage(HttpStatusCode.OK)
{
    Content = new StringContent("<html>synthetic login</html>", Encoding.UTF8, "text/html"),
})
{
    Must(RuntimeManager.IsLoginPageReady(response), "HTTP 200 HTML must pass login readiness");
}
Console.WriteLine("PC HTTP login-readiness guard passed (8 response cases).");

var testRoot = Path.Combine(Path.GetTempPath(),
    "linguacafe-pc-state-synthetic-" + Guid.NewGuid().ToString("N"));
Directory.CreateDirectory(testRoot);
var statePath = Path.Combine(testRoot, "state.json");
var syntheticPassword = "synthetic-" + Convert.ToHexString(RandomNumberGenerator.GetBytes(24));
try
{
    var initial = new PcTestState
    {
        AdminEmail = "synthetic-admin@invalid.example",
        AdminPassword = syntheticPassword,
        AdminProvisioned = true,
    };
    PcTestStatePersistence.Save(statePath, initial);
    var firstRaw = File.ReadAllText(statePath);
    using (var document = JsonDocument.Parse(firstRaw))
    {
        Must(document.RootElement.GetProperty("SchemaVersion").GetInt32() == 2,
            "new state must be explicitly versioned v2");
        Must(!document.RootElement.TryGetProperty("AdminPassword", out _),
            "new JSON must not serialize a clear password");
        Must(document.RootElement.TryGetProperty("AdminPasswordProtected", out var encrypted)
             && encrypted.GetString()?.Length > 40,
            "new JSON must contain a DPAPI blob");
    }
    Must(!firstRaw.Contains(syntheticPassword, StringComparison.Ordinal),
        "new file must not contain the clear password");
    var runtime = new RuntimeManager(testRoot, Path.Combine(testRoot, "no-real-legacy-state.json"));
    var reloaded = runtime.LoadOrCreateState();
    Must(reloaded.AdminPassword == syntheticPassword &&
         reloaded.AdminEmail == initial.AdminEmail &&
         reloaded.AdminProvisioned,
        "new secure state must survive reload");
    runtime.SaveState(reloaded);
    Must(runtime.LoadOrCreateState().AdminPassword == syntheticPassword,
        "existing encrypted state must survive atomic replace");

    var legacyJson = JsonSerializer.Serialize(new
    {
        AdminEmail = "legacy-synthetic@invalid.example",
        AdminPassword = syntheticPassword,
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, legacyJson);
    // Interrupted older migrations must not trick the loader into consuming
    // an untrusted leftover staging file instead of the canonical state.
    var interruptedStage = Path.Combine(testRoot, ".pc-test-state-interrupted.tmp");
    File.WriteAllText(interruptedStage, "synthetic-untrusted-staging");
    var migrated = runtime.LoadOrCreateState();
    Must(File.ReadAllText(interruptedStage) == "synthetic-untrusted-staging",
        "migration must ignore unrelated orphaned staging files");
    Must(migrated.AdminPassword == syntheticPassword &&
         migrated.AdminEmail == "legacy-synthetic@invalid.example" &&
         migrated.AdminProvisioned,
        "legacy state must preserve the original synthetic identity");
    var upgradedRaw = File.ReadAllText(statePath);
    Must(!upgradedRaw.Contains(syntheticPassword, StringComparison.Ordinal),
        "successful legacy migration must remove clear JSON password");
    using (var document = JsonDocument.Parse(upgradedRaw))
    {
        Must(!document.RootElement.TryGetProperty("AdminPassword", out _),
            "migrated JSON must not include a clear password field");
    }
    Must(runtime.LoadOrCreateState().AdminPassword == syntheticPassword,
        "migrated state must reload through the actual RuntimeManager current-user path");

    var versionedLegacy = JsonSerializer.Serialize(new
    {
        SchemaVersion = 1,
        AdminEmail = "versioned-legacy@invalid.example",
        AdminPassword = syntheticPassword,
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, versionedLegacy);
    var versionedMigrated = runtime.LoadOrCreateState();
    Must(versionedMigrated.AdminPassword == syntheticPassword &&
         versionedMigrated.SchemaVersion == 2,
        "explicit legacy v1 state must migrate to encrypted v2");
    using (var document = JsonDocument.Parse(File.ReadAllText(statePath)))
    {
        Must(document.RootElement.GetProperty("SchemaVersion").GetInt32() == 2 &&
             !document.RootElement.TryGetProperty("AdminPassword", out _),
            "explicit v1 must not leave a clear password after migration");
    }

    var corrupt = JsonSerializer.Serialize(new
    {
        AdminEmail = "legacy-synthetic@invalid.example",
        AdminPassword = syntheticPassword,
        AdminPasswordProtected = "not-valid-base64",
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, corrupt);
    var rejectedCorruption = false;
    try
    {
        PcTestStatePersistence.Load(statePath);
    }
    catch (InvalidOperationException)
    {
        rejectedCorruption = true;
    }
    Must(rejectedCorruption, "corrupted ciphertext must fail closed");
    Must(File.ReadAllText(statePath) == corrupt,
        "a rejected corrupt state must stay unchanged, without downgrading to clear credentials");

    var downgrade = JsonSerializer.Serialize(new
    {
        SchemaVersion = 2,
        AdminEmail = "legacy-synthetic@invalid.example",
        AdminPassword = syntheticPassword,
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, downgrade);
    var rejectedDowngrade = false;
    try
    {
        PcTestStatePersistence.Load(statePath);
    }
    catch (InvalidOperationException)
    {
        rejectedDowngrade = true;
    }
    Must(rejectedDowngrade && File.ReadAllText(statePath) == downgrade,
        "a v2 state must never downgrade to a legacy plaintext credential");

    var wrongPurposePassword = Encoding.UTF8.GetBytes(syntheticPassword);
    var differentPurposeBlob = ProtectedData.Protect(
        wrongPurposePassword, Encoding.UTF8.GetBytes("incorrect-purpose"),
        DataProtectionScope.CurrentUser);
    CryptographicOperations.ZeroMemory(wrongPurposePassword);
    var foreignBlobJson = JsonSerializer.Serialize(new
    {
        SchemaVersion = 2,
        AdminEmail = "legacy-synthetic@invalid.example",
        AdminPasswordProtected = Convert.ToBase64String(differentPurposeBlob),
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, foreignBlobJson);
    var rejectedDifferentPurpose = false;
    try
    {
        PcTestStatePersistence.Load(statePath);
    }
    catch (InvalidOperationException)
    {
        rejectedDifferentPurpose = true;
    }
    Must(rejectedDifferentPurpose && File.ReadAllText(statePath) == foreignBlobJson,
        "valid DPAPI blob from a different purpose must fail closed");

    var futureStateJson = JsonSerializer.Serialize(new
    {
        SchemaVersion = 3,
        AdminEmail = "legacy-synthetic@invalid.example",
        AdminPasswordProtected = initial.AdminPasswordProtected,
        AdminProvisioned = true,
    });
    File.WriteAllText(statePath, futureStateJson);
    var rejectedFutureSchema = false;
    try
    {
        PcTestStatePersistence.Load(statePath);
    }
    catch (InvalidOperationException)
    {
        rejectedFutureSchema = true;
    }
    Must(rejectedFutureSchema && File.ReadAllText(statePath) == futureStateJson,
        "future schema must not be silently accepted or overwritten");

    File.WriteAllText(statePath, "{\"AdminEmail\":\"missing@invalid.example\",\"AdminProvisioned\":true}");
    var rejectedMissing = false;
    try
    {
        PcTestStatePersistence.Load(statePath);
    }
    catch (InvalidOperationException)
    {
        rejectedMissing = true;
    }
    Must(rejectedMissing, "missing secure and legacy credentials must not silently recreate admin");

    // Exercise the real RuntimeManager first-run path exclusively within synthetic
    // folders; never consult the installed D-drive or existing C-drive state.
    var freshRoot = Path.Combine(testRoot, "fresh-identity");
    var newRuntime = new RuntimeManager(
        freshRoot, Path.Combine(testRoot, "no-real-legacy-state.json"));
    var freshIdentity = newRuntime.LoadOrCreateState();
    Must(freshIdentity.AdminPassword.Length >= 24 &&
         !freshIdentity.AdminProvisioned &&
         freshIdentity.AdminEmail == "pc-test-admin@local.invalid",
        "first-run state must create one new synthetic identity before setup");
    var freshRaw = File.ReadAllText(Path.Combine(freshRoot, "state.json"));
    using (var doc = JsonDocument.Parse(freshRaw))
    {
        Must(!doc.RootElement.TryGetProperty("AdminPassword", out _) &&
             doc.RootElement.TryGetProperty("AdminPasswordProtected", out _),
            "first-run runtime must persist only encrypted credentials");
    }
    Must(newRuntime.LoadOrCreateState().AdminPassword == freshIdentity.AdminPassword,
        "first-run identity must remain stable after restart");

    var syntheticLegacyMarker = Path.Combine(testRoot, "synthetic-legacy-state.json");
    File.WriteAllText(syntheticLegacyMarker, "{\"synthetic\":true}");
    var blockedRoot = Path.Combine(testRoot, "blocked-identity");
    var blockedRuntime = new RuntimeManager(blockedRoot, syntheticLegacyMarker);
    var blockedLegacyState = false;
    try
    {
        blockedRuntime.LoadOrCreateState();
    }
    catch (InvalidOperationException)
    {
        blockedLegacyState = true;
    }
    Must(blockedLegacyState &&
         !File.Exists(Path.Combine(blockedRoot, "state.json")),
        "old C-drive identity guard must prevent silent recreation");

    File.Delete(statePath);
    var rejectedEmpty = false;
    try
    {
        PcTestStatePersistence.Save(statePath, new PcTestState());
    }
    catch (InvalidOperationException)
    {
        rejectedEmpty = true;
    }
    Must(rejectedEmpty && !File.Exists(statePath),
        "an empty password must not persist a usable admin state");

    Console.WriteLine("PC synthetic current-user encrypted-state smoke passed (fresh, save, migration, corruption, missing and empty guards).");
}
finally
{
    if (Directory.Exists(testRoot))
    {
        Directory.Delete(testRoot, recursive: true);
    }
}
