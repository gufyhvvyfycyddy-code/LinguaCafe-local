import XCTest

final class ReaderAcceptanceUITests: XCTestCase {
    override func setUpWithError() throws {
        continueAfterFailure = false
        XCUIDevice.shared.orientation = .portrait
    }

    func testLoginPersistsAuthenticatedSession() throws {
        let app = XCUIApplication()
        let environment = ProcessInfo.processInfo.environment
        let serverURL = try requiredEnvironment("LC_SERVER_URL", environment)
        let email = try requiredEnvironment("LC_TEST_EMAIL", environment)
        let password = try requiredEnvironment("LC_TEST_PASSWORD", environment)

        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.staticTexts["IOS · CONNECTED MVP"].waitForExistence(timeout: 60))

        let serverField = app.textFields.element(boundBy: 0)
        XCTAssertTrue(serverField.waitForExistence(timeout: 30))
        try focusAndType(serverURL, into: serverField, in: app)

        let emailField = app.textFields.element(boundBy: 1)
        XCTAssertTrue(emailField.waitForExistence(timeout: 15))
        try focusAndType(email, into: emailField, in: app)

        let passwordField = app.secureTextFields.element(boundBy: 0)
        XCTAssertTrue(passwordField.waitForExistence(timeout: 15))
        try focusAndType(password, into: passwordField, in: app)

        let loginButton = app.buttons["安全登录"]
        XCTAssertTrue(loginButton.waitForExistence(timeout: 15))
        loginButton.tap()
        Thread.sleep(forTimeInterval: 2.0)
        let homeButton = app.buttons["首页"].firstMatch
        if !homeButton.exists {
            print("LOGIN_DIAGNOSTICS\n\(app.debugDescription)")
            attachScreenshot(XCUIScreen.main.screenshot(), named: "login-diagnostics")
        }
        XCTAssertTrue(homeButton.waitForExistence(timeout: 60))
    }

    func testReaderPortraitSourceBinding() throws {
        let app = XCUIApplication()
        let marker = try requiredEnvironment("LC_READER_MARKER", ProcessInfo.processInfo.environment)
        try launchAuthenticatedReader(app: app, marker: marker)

        let bank = app.buttons["bank"].firstMatch
        let account = app.buttons["account"].firstMatch
        XCTAssertTrue(bank.waitForExistence(timeout: 30))
        XCTAssertTrue(account.waitForExistence(timeout: 30))
        let portraitScreenshot = XCUIScreen.main.screenshot()
        XCTAssertGreaterThan(portraitScreenshot.image.size.height, portraitScreenshot.image.size.width)
        attachScreenshot(portraitScreenshot, named: "reader-portrait")

        bank.tap()
        XCTAssertTrue(app.staticTexts["创建学习词义"].waitForExistence(timeout: 30))
        let meaningField = app.textFields["输入你确认的中文词义"].firstMatch
        XCTAssertTrue(meaningField.waitForExistence(timeout: 15))
        meaningField.tap()
        meaningField.typeText("testing bank meaning")
        let createButton = app.buttons["确认并创建"]
        XCTAssertTrue(createButton.waitForExistence(timeout: 15))
        createButton.tap()

        XCTAssertTrue(app.buttons["bank"].firstMatch.waitForExistence(timeout: 30))
        app.buttons["bank"].firstMatch.tap()
        XCTAssertTrue(app.buttons["认识 / 记得"].waitForExistence(timeout: 30))
        XCTAssertTrue(app.buttons["不认识"].exists)
        XCTAssertFalse(app.staticTexts["创建学习词义"].exists)
        let closeButton = app.buttons["关闭"]
        XCTAssertTrue(closeButton.waitForExistence(timeout: 15))
        closeButton.tap()
    }

    func testTextImportThroughSystemFilesPicker() throws {
        let app = XCUIApplication()
        let marker = try requiredEnvironment("LC_READER_MARKER", ProcessInfo.processInfo.environment)
        let invalidExtension = "lc-\(marker)-invalid.pdf"
        let invalidUtf8 = "lc-\(marker)-invalid-utf8.txt"
        let oversized = "lc-\(marker)-oversize.txt"
        let valid = "lc-\(marker)-valid.txt"
        let validBookName = valid.replacingOccurrences(of: ".txt", with: "")

        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        try openTextImportSettings(app: app)

        let fileButton = app.buttons["选择文本文件"].firstMatch
        fileButton.tap()
        try openLocalFilesLocation(app: app)
        let rejectedExtension = pickerFileCell(named: invalidExtension, in: app)
        XCTAssertTrue(rejectedExtension.waitForExistence(timeout: 10))
        XCTAssertFalse(rejectedExtension.isEnabled)
        try cancelDocumentPicker(app: app)
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))

        try openTextImportSettings(app: app)
        try chooseFile(named: invalidUtf8, app: app)
        app.buttons["导入到服务器"].tap()
        XCTAssertTrue(app.staticTexts["文本文件必须使用 UTF-8 编码"].waitForExistence(timeout: 20))

        try openTextImportSettings(app: app)
        try chooseFile(named: oversized, app: app)
        app.buttons["导入到服务器"].tap()
        XCTAssertTrue(app.staticTexts["文件需为 1–200 KB，且资料和章节名称不能为空"].waitForExistence(timeout: 20))

        try openTextImportSettings(app: app)
        try chooseFile(named: valid, app: app)
        app.buttons["导入到服务器"].tap()
        let importedBook = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            validBookName
        )).firstMatch
        XCTAssertTrue(importedBook.waitForExistence(timeout: 60))
    }

    func testOfflineWarmCaches() throws {
        let app = XCUIApplication()
        let marker = try requiredEnvironment("LC_READER_MARKER", ProcessInfo.processInfo.environment)
        let offlineLemma = "offline"

        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCUIDevice.shared.orientation = .portrait
        let portraitState = XCUIScreen.main.screenshot()
        XCTAssertGreaterThan(portraitState.image.size.height, portraitState.image.size.width)
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))

        let reading = app.buttons["阅读"].firstMatch
        XCTAssertTrue(reading.waitForExistence(timeout: 20))
        reading.tap()
        let book = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "H10 iOS Reader \(marker)"
        )).firstMatch
        XCTAssertTrue(book.waitForExistence(timeout: 60))
        book.tap()
        let download = app.buttons["下载整套"].firstMatch
        XCTAssertTrue(download.waitForExistence(timeout: 30))
        download.tap()
        XCTAssertTrue(app.staticTexts["整套已下载，可离线打开"].waitForExistence(timeout: 60))
        let chapter = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "Reader touch source binding"
        )).firstMatch
        XCTAssertTrue(chapter.waitForExistence(timeout: 30))
        chapter.tap()
        XCTAssertTrue(app.buttons["bank"].firstMatch.waitForExistence(timeout: 30))

        let review = app.buttons["复习"].firstMatch
        XCTAssertTrue(review.waitForExistence(timeout: 20))
        review.tap()
        XCTAssertTrue(app.staticTexts[offlineLemma].waitForExistence(timeout: 60))
        let wordAudio = app.buttons["🔊 词发音"].firstMatch
        XCTAssertTrue(wordAudio.waitForExistence(timeout: 20))
        try tapReviewControl(wordAudio, in: app)
        Thread.sleep(forTimeInterval: 1.0)
        let playbackStatus = app.staticTexts["正在播放词发音"].firstMatch
        if !playbackStatus.exists {
            print("AUDIO_PLAYBACK_DIAGNOSTICS\n\(app.debugDescription)")
            attachScreenshot(XCUIScreen.main.screenshot(), named: "offline-audio-playback-diagnostics")
        }
        XCTAssertTrue(playbackStatus.waitForExistence(timeout: 20))
    }

    func testOfflineCachedContentAndQueuesGood() throws {
        let app = XCUIApplication()
        let marker = try requiredEnvironment("LC_READER_MARKER", ProcessInfo.processInfo.environment)
        let offlineLemma = "offline"

        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCUIDevice.shared.orientation = .portrait
        let portraitState = XCUIScreen.main.screenshot()
        XCTAssertGreaterThan(portraitState.image.size.height, portraitState.image.size.width)
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        XCTAssertTrue(app.staticTexts["服务器不可达"].waitForExistence(timeout: 60))

        app.buttons["阅读"].firstMatch.tap()
        let book = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "H10 iOS Reader \(marker)"
        )).firstMatch
        XCTAssertTrue(book.waitForExistence(timeout: 30))
        book.tap()
        XCTAssertTrue(app.staticTexts["离线文章包"].waitForExistence(timeout: 30))
        let chapter = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "Reader touch source binding"
        )).firstMatch
        XCTAssertTrue(chapter.waitForExistence(timeout: 30))
        chapter.tap()
        XCTAssertTrue(app.staticTexts["离线文章包"].waitForExistence(timeout: 30))
        XCTAssertTrue(app.buttons["bank"].firstMatch.waitForExistence(timeout: 30))

        app.buttons["复习"].firstMatch.tap()
        XCTAssertTrue(app.staticTexts["离线复习包 · 评分会排队同步"].waitForExistence(timeout: 30))
        XCTAssertTrue(app.staticTexts[offlineLemma].waitForExistence(timeout: 30))
        let wordAudio = app.buttons["🔊 词发音"].firstMatch
        XCTAssertTrue(wordAudio.waitForExistence(timeout: 20))
        try tapReviewControl(wordAudio, in: app)
        XCTAssertTrue(app.staticTexts["正在播放词发音"].waitForExistence(timeout: 20))
        let reveal = app.buttons["显示答案"].firstMatch
        XCTAssertTrue(reveal.waitForExistence(timeout: 20))
        try tapReviewControl(reveal, in: app)
        let good = app.buttons.matching(NSPredicate(format: "label CONTAINS %@", "良好")).firstMatch
        XCTAssertTrue(good.waitForExistence(timeout: 20))
        try tapReviewControl(good, in: app)
        XCTAssertTrue(app.staticTexts[offlineLemma].waitForNonExistence(timeout: 30))

        let settings = app.buttons["我的"].firstMatch
        XCTAssertTrue(settings.waitForExistence(timeout: 20))
        settings.tap()
        XCTAssertTrue(app.staticTexts["1 个操作待同步；0 个操作需要处理。"].waitForExistence(timeout: 30))
    }

    func testOfflinePendingSurvivesRelaunch() throws {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        let unreachable = app.staticTexts.matching(NSPredicate(
            format: "label BEGINSWITH %@",
            "服务器不可达"
        )).firstMatch
        XCTAssertTrue(unreachable.waitForExistence(timeout: 60))
        let settings = app.buttons["我的"].firstMatch
        XCTAssertTrue(settings.waitForExistence(timeout: 20))
        settings.tap()
        XCTAssertTrue(app.staticTexts["1 个操作待同步；0 个操作需要处理。"].waitForExistence(timeout: 30))
    }

    func testOfflineReconnectAutomaticallySyncs() throws {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        XCTAssertTrue(app.staticTexts["在线"].waitForExistence(timeout: 60))
        let settings = app.buttons["我的"].firstMatch
        XCTAssertTrue(settings.waitForExistence(timeout: 20))
        settings.tap()
        XCTAssertTrue(app.staticTexts["0 个操作待同步；0 个操作需要处理。"].waitForExistence(timeout: 60))
    }

    func testOfflineReconnectEmptyQueueRemainsStable() throws {
        let app = XCUIApplication()
        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        XCTAssertTrue(app.staticTexts["在线"].waitForExistence(timeout: 60))
        let settings = app.buttons["我的"].firstMatch
        XCTAssertTrue(settings.waitForExistence(timeout: 20))
        settings.tap()
        XCTAssertTrue(app.staticTexts["0 个操作待同步；0 个操作需要处理。"].waitForExistence(timeout: 60))
    }

    func testReaderLandscapePhraseGesture() throws {
        let app = XCUIApplication()
        let marker = try requiredEnvironment("LC_READER_MARKER", ProcessInfo.processInfo.environment)
        try launchAuthenticatedReader(app: app, marker: marker)

        XCUIDevice.shared.orientation = .landscapeRight
        let landscapeBank = app.buttons["bank"].firstMatch
        let landscapeAccount = app.buttons["account"].firstMatch
        XCTAssertTrue(landscapeBank.waitForExistence(timeout: 30))
        XCTAssertTrue(landscapeAccount.waitForExistence(timeout: 30))
        landscapeBank.press(
            forDuration: 0.7,
            thenDragTo: landscapeAccount,
            withVelocity: .slow,
            thenHoldForDuration: 0.2
        )

        XCTAssertTrue(app.staticTexts["bank account"].waitForExistence(timeout: 30))
        XCTAssertTrue(app.staticTexts["LOCAL DICTIONARY"].waitForExistence(timeout: 15))
        XCTAssertTrue(app.staticTexts["创建学习词义"].waitForExistence(timeout: 15))
        XCTAssertFalse(app.staticTexts["先回想这个词在这里的意思，再选择你的真实情况。"].exists)
        let landscapeScreenshot = XCUIScreen.main.screenshot()
        XCTAssertGreaterThan(landscapeScreenshot.image.size.width, landscapeScreenshot.image.size.height)
        attachScreenshot(landscapeScreenshot, named: "reader-landscape")
    }

    private func launchAuthenticatedReader(app: XCUIApplication, marker: String) throws {
        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))

        let readerTab = app.buttons["阅读"]
        XCTAssertTrue(readerTab.waitForExistence(timeout: 30))
        readerTab.tap()

        let book = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "H10 iOS Reader \(marker)"
        )).firstMatch
        XCTAssertTrue(book.waitForExistence(timeout: 60))
        book.tap()

        let chapter = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            "Reader touch source binding"
        )).firstMatch
        XCTAssertTrue(chapter.waitForExistence(timeout: 30))
        chapter.tap()
        XCTAssertTrue(app.staticTexts["Reader touch source binding"].waitForExistence(timeout: 30))
    }

    private func openTextImportSettings(app: XCUIApplication) throws {
        let settings = app.buttons["我的"].firstMatch
        XCTAssertTrue(settings.waitForExistence(timeout: 15))
        settings.tap()
        let fileButton = app.buttons["选择文本文件"].firstMatch
        XCTAssertTrue(fileButton.waitForExistence(timeout: 15))
        scrollUntilHittable(fileButton, in: app)
        if !fileButton.isHittable {
            attachPickerDiagnostics(app: app, named: "text-import-file-button-not-hittable")
            XCTFail("Text import file button remained offscreen")
            throw NSError(
                domain: "LinguaCafeTextImportAcceptance",
                code: 6,
                userInfo: [NSLocalizedDescriptionKey: "Text import file button remained offscreen"]
            )
        }
    }

    private func chooseFile(named fileName: String, app: XCUIApplication) throws {
        let fileButton = app.buttons["选择文本文件"].firstMatch
        scrollUntilHittable(fileButton, in: app)
        XCTAssertTrue(fileButton.isHittable)
        fileButton.tap()
        try openLocalFilesLocation(app: app)

        let file = pickerFileCell(named: fileName, in: app)
        if !file.waitForExistence(timeout: 20) {
            attachPickerDiagnostics(app: app, named: "import-picker-file-missing")
            XCTFail("Files picker did not expose staged fixture: \(fileName)")
            throw NSError(
                domain: "LinguaCafeTextImportAcceptance",
                code: 2,
                userInfo: [NSLocalizedDescriptionKey: "Missing staged Files fixture: \(fileName)"]
            )
        }
        file.tap()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        let stem = URL(fileURLWithPath: fileName).deletingPathExtension().lastPathComponent
        let selectedName = app.textFields.matching(NSPredicate(
            format: "label == %@ AND value == %@",
            "导入资料名称",
            stem
        )).firstMatch
        if !selectedName.waitForExistence(timeout: 30) {
            attachPickerDiagnostics(app: app, named: "import-name-not-auto-filled")
        }
        XCTAssertTrue(selectedName.exists)
    }

    private func pickerFileCell(named fileName: String, in app: XCUIApplication) -> XCUIElement {
        let url = URL(fileURLWithPath: fileName)
        let stem = url.deletingPathExtension().lastPathComponent
        let fileExtension = url.pathExtension
        return app.cells.matching(identifier: "\(stem), \(fileExtension)").firstMatch
    }

    private func openLocalFilesLocation(app: XCUIApplication) throws {
        let localTitle = app.navigationBars.staticTexts["On My iPhone"].firstMatch
        if localTitle.exists {
            return
        }

        let browse = app.tabBars.buttons["Browse"].firstMatch
        if !browse.waitForExistence(timeout: 10) {
            attachPickerDiagnostics(app: app, named: "import-picker-browse-missing")
            XCTFail("System document picker did not expose Browse")
            throw NSError(
                domain: "LinguaCafeTextImportAcceptance",
                code: 4,
                userInfo: [NSLocalizedDescriptionKey: "Missing Browse control"]
            )
        }
        browse.tap()
        if localTitle.waitForExistence(timeout: 5) {
            return
        }

        let localFiles = app.staticTexts["On My iPhone"].firstMatch
        if !localFiles.waitForExistence(timeout: 15) || !localFiles.isHittable {
            attachPickerDiagnostics(app: app, named: "import-picker-local-location-missing")
            XCTFail("System document picker did not expose On My iPhone")
            throw NSError(
                domain: "LinguaCafeTextImportAcceptance",
                code: 5,
                userInfo: [NSLocalizedDescriptionKey: "Missing On My iPhone location"]
            )
        }
        localFiles.tap()
        XCTAssertTrue(localTitle.waitForExistence(timeout: 15))
    }

    private func cancelDocumentPicker(app: XCUIApplication) throws {
        let cancel = app.buttons.matching(NSPredicate(
            format: "label IN %@",
            ["Cancel", "取消"]
        )).firstMatch
        if !cancel.waitForExistence(timeout: 15) {
            attachPickerDiagnostics(app: app, named: "import-picker-cancel-missing")
            XCTFail("System document picker did not expose a cancel control")
            throw NSError(
                domain: "LinguaCafeTextImportAcceptance",
                code: 3,
                userInfo: [NSLocalizedDescriptionKey: "Missing document picker cancel control"]
            )
        }
        cancel.tap()
    }

    private func attachPickerDiagnostics(app: XCUIApplication, named name: String) {
        print(app.debugDescription)
        attachScreenshot(XCUIScreen.main.screenshot(), named: name)
    }

    private func reviewTapSafeVerticalBounds(in app: XCUIApplication) -> (top: CGFloat, bottom: CGFloat) {
        let appFrame = app.frame
        let topbarHome = app.buttons["首页"].firstMatch
        let safeTop = topbarHome.exists && !topbarHome.frame.isEmpty
            ? max(appFrame.minY, topbarHome.frame.maxY + 8)
            : appFrame.minY
        let navLabels = ["阅读", "复习", "生词", "我的"]
        let navTop = navLabels.compactMap { label -> CGFloat? in
            let button = app.buttons[label].firstMatch
            return button.exists && !button.frame.isEmpty ? button.frame.minY : nil
        }.min() ?? appFrame.maxY
        return (safeTop, min(appFrame.maxY, navTop - 8))
    }

    private func tapReviewControl(_ element: XCUIElement, in app: XCUIApplication) throws {
        let webView = app.webViews.firstMatch
        let scrollTarget = webView.exists ? webView : app
        for _ in 0..<10 {
            let frame = element.frame
            let appFrame = app.frame
            let safeBounds = reviewTapSafeVerticalBounds(in: app)
            if !frame.isEmpty,
               frame.minX >= appFrame.minX,
               frame.maxX <= appFrame.maxX,
               frame.minY >= safeBounds.top,
               frame.maxY <= safeBounds.bottom {
                app.coordinate(withNormalizedOffset: CGVector(dx: 0, dy: 0))
                    .withOffset(CGVector(dx: frame.midX - appFrame.minX, dy: frame.midY - appFrame.minY))
                    .tap()
                return
            }
            if !frame.isEmpty && frame.minY < safeBounds.top {
                scrollTarget.swipeDown()
            } else {
                scrollTarget.swipeUp()
            }
        }
        print(app.debugDescription)
        attachScreenshot(XCUIScreen.main.screenshot(), named: "offline-review-control-offscreen")
        XCTFail("Offline review control never entered the unobstructed area between fixed navigation bars")
        throw NSError(
            domain: "LinguaCafeOfflineAcceptance",
            code: 7,
            userInfo: [NSLocalizedDescriptionKey: "Offline review control remained offscreen or under fixed navigation"]
        )
    }

    private func scrollUntilHittable(_ element: XCUIElement, in app: XCUIApplication) {
        let webView = app.webViews.firstMatch
        let scrollTarget = webView.exists ? webView : app
        for _ in 0..<10 where !element.isHittable {
            scrollTarget.swipeUp()
        }
    }

    private func focusAndType(
        _ text: String,
        into element: XCUIElement,
        in app: XCUIApplication
    ) throws {
        element.tap()
        let keyboard = app.keyboards.firstMatch
        if !keyboard.waitForExistence(timeout: 5) {
            element.tap()
            XCTAssertTrue(keyboard.waitForExistence(timeout: 5))
        }
        app.typeText(text)
    }

    private func requiredEnvironment(
        _ name: String,
        _ environment: [String: String]
    ) throws -> String {
        guard let value = environment[name], !value.isEmpty else {
            XCTFail("Missing required UI-test environment variable: \(name)")
            throw NSError(
                domain: "LinguaCafeReaderAcceptance",
                code: 1,
                userInfo: [NSLocalizedDescriptionKey: "Missing required UI-test environment variable: \(name)"]
            )
        }
        return value
    }

    private func attachScreenshot(_ screenshot: XCUIScreenshot, named name: String) {
        let attachment = XCTAttachment(screenshot: screenshot)
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
    }
}
