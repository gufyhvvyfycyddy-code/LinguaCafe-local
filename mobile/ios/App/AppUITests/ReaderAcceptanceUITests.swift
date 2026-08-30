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
        serverField.tap()
        serverField.typeText(serverURL)

        let emailField = app.textFields.element(boundBy: 1)
        XCTAssertTrue(emailField.waitForExistence(timeout: 15))
        emailField.tap()
        emailField.typeText(email)

        let passwordField = app.secureTextFields.element(boundBy: 0)
        XCTAssertTrue(passwordField.waitForExistence(timeout: 15))
        passwordField.tap()
        passwordField.typeText(password)

        let loginButton = app.buttons["安全登录"]
        XCTAssertTrue(loginButton.waitForExistence(timeout: 15))
        loginButton.tap()
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
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
        let bookName = "H10 iOS Import \(marker)"
        let invalidExtension = "lc-\(marker)-invalid.pdf"
        let invalidUtf8 = "lc-\(marker)-invalid-utf8.txt"
        let oversized = "lc-\(marker)-oversize.txt"
        let valid = "lc-\(marker)-valid.txt"

        app.launch()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))
        XCTAssertTrue(app.buttons["首页"].waitForExistence(timeout: 60))
        app.buttons["我的"].tap()

        let fileButton = app.buttons["选择文本文件"].firstMatch
        scrollUntilHittable(fileButton, in: app)
        XCTAssertTrue(fileButton.isHittable)
        let bookField = app.textFields["导入资料名称"].firstMatch
        XCTAssertTrue(bookField.waitForExistence(timeout: 15))
        bookField.tap()
        bookField.typeText(bookName)

        fileButton.tap()
        let rejectedExtension = pickerElement(label: invalidExtension, in: app)
        if rejectedExtension.exists {
            XCTAssertFalse(rejectedExtension.isHittable && rejectedExtension.isEnabled)
        }
        let cancel = app.buttons.matching(NSPredicate(
            format: "label IN %@",
            ["Cancel", "取消"]
        )).firstMatch
        if !cancel.waitForExistence(timeout: 15) {
            attachPickerDiagnostics(app: app, named: "import-picker-cancel-missing")
            XCTFail("System document picker did not expose a cancel control")
            return
        }
        cancel.tap()
        XCTAssertTrue(app.wait(for: .runningForeground, timeout: 30))

        try chooseFile(named: invalidUtf8, app: app)
        app.buttons["导入到服务器"].tap()
        XCTAssertTrue(app.staticTexts["文本文件必须使用 UTF-8 编码"].waitForExistence(timeout: 20))

        try chooseFile(named: oversized, app: app)
        app.buttons["导入到服务器"].tap()
        XCTAssertTrue(app.staticTexts["文件需为 1–200 KB，且资料和章节名称不能为空"].waitForExistence(timeout: 20))

        try chooseFile(named: valid, app: app)
        app.buttons["导入到服务器"].tap()
        let importedBook = app.buttons.matching(NSPredicate(
            format: "label CONTAINS %@",
            bookName
        )).firstMatch
        XCTAssertTrue(importedBook.waitForExistence(timeout: 60))
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

    private func chooseFile(named fileName: String, app: XCUIApplication) throws {
        let fileButton = app.buttons["选择文本文件"].firstMatch
        scrollUntilHittable(fileButton, in: app)
        XCTAssertTrue(fileButton.isHittable)
        fileButton.tap()

        let file = pickerElement(label: fileName, in: app)
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
        XCTAssertTrue(app.buttons["导入到服务器"].waitForExistence(timeout: 30))
    }

    private func pickerElement(label: String, in app: XCUIApplication) -> XCUIElement {
        app.descendants(matching: .any)
            .matching(NSPredicate(format: "label == %@", label))
            .firstMatch
    }

    private func attachPickerDiagnostics(app: XCUIApplication, named name: String) {
        print(app.debugDescription)
        attachScreenshot(XCUIScreen.main.screenshot(), named: name)
    }

    private func scrollUntilHittable(_ element: XCUIElement, in app: XCUIApplication) {
        for _ in 0..<6 where !element.isHittable {
            app.swipeUp()
        }
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
