import Foundation

enum NativeLanguage {
    private static let key = "omnigo.language"
    private static let messages: [String: [String: String]] = {
        guard let url = Bundle.main.url(forResource: "native-translations", withExtension: "json"),
              let data = try? Data(contentsOf: url),
              let result = try? JSONDecoder().decode([String: [String: String]].self, from: data) else { return [:] }
        return result
    }()
    static func set(_ language: String) -> Bool {
        guard ["tr", "en", "de"].contains(language) else { return false }
        UserDefaults.standard.set(language, forKey: key)
        return true
    }
    static func t(_ source: String, _ values: String...) -> String {
        let language = UserDefaults.standard.string(forKey: key) ?? "tr"
        var result = messages[source]?[language] ?? source
        for (index, value) in values.enumerated() {
            result = result.replacingOccurrences(of: "{\(index)}", with: value)
        }
        return result
    }
}
