package com.linguacafe.mobile;

import android.os.Bundle;
import android.webkit.WebView;
import androidx.activity.OnBackPressedCallback;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(SecureTokenPlugin.class);
        super.onCreate(savedInstanceState);

        OnBackPressedCallback webHistoryBack = new OnBackPressedCallback(false) {
            @Override
            public void handleOnBackPressed() {
                bridge.getWebView().goBack();
            }
        };
        getOnBackPressedDispatcher().addCallback(this, webHistoryBack);

        bridge.setWebViewClient(new BridgeWebViewClient(bridge) {
            @Override
            public void doUpdateVisitedHistory(WebView view, String url, boolean isReload) {
                super.doUpdateVisitedHistory(view, url, isReload);
                webHistoryBack.setEnabled(view.canGoBack());
            }
        });
        webHistoryBack.setEnabled(bridge.getWebView().canGoBack());
    }
}
