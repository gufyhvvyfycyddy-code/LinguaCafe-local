import Capacitor

final class MyViewController: CAPBridgeViewController {
    override func capacitorDidLoad() {
        super.capacitorDidLoad()
        bridge?.registerPluginInstance(SecureTokenPlugin())
    }
}
