import Capacitor
import Foundation
import Security

@objc(SecureTokenPlugin)
public final class SecureTokenPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "SecureTokenPlugin"
    public let jsName = "SecureToken"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "save", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "load", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "clear", returnType: CAPPluginReturnPromise)
    ]

    private let account = "mobile-session-v1"

    private var service: String {
        (Bundle.main.bundleIdentifier ?? "com.linguacafe.mobile") + ".secure-token"
    }

    @objc func save(_ call: CAPPluginCall) {
        guard let token = call.getString("token"), !token.isEmpty,
              let data = token.data(using: .utf8) else {
            call.reject("Token is required.")
            return
        }

        let query = baseQuery()
        SecItemDelete(query as CFDictionary)

        var item = query
        item[kSecValueData as String] = data
        item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly

        guard SecItemAdd(item as CFDictionary, nil) == errSecSuccess else {
            call.reject("Unable to protect the device token.")
            return
        }
        call.resolve()
    }

    @objc func load(_ call: CAPPluginCall) {
        var query = baseQuery()
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        if status == errSecItemNotFound {
            call.resolve(["token": NSNull()])
            return
        }
        guard status == errSecSuccess,
              let data = result as? Data,
              let token = String(data: data, encoding: .utf8),
              !token.isEmpty else {
            SecItemDelete(baseQuery() as CFDictionary)
            call.resolve(["token": NSNull()])
            return
        }
        call.resolve(["token": token])
    }

    @objc func clear(_ call: CAPPluginCall) {
        let status = SecItemDelete(baseQuery() as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else {
            call.reject("Unable to clear the device token.")
            return
        }
        call.resolve()
    }

    private func baseQuery() -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account
        ]
    }
}
