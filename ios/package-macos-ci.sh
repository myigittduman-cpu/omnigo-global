#!/usr/bin/env bash
set -euo pipefail
umask 077
export LC_ALL=C

fail() {
  printf '%s\n' "$1" >&2
  exit 1
}

[[ "$(uname -s)" == Darwin && -n "${RUNNER_TEMP:-}" ]] ||
  fail 'Bu betik GitHub Actions macOS çalıştırıcısı için hazırlanmıştır.'
[[ "${OMNIGO_APPLE_TEAM_ID:-}" =~ ^[A-Z0-9]{10}$ ]] ||
  fail 'OMNIGO_APPLE_TEAM_ID depo değişkeni eksik veya geçersiz.'
for name in OMNIGO_MAC_APP_P12 OMNIGO_MAC_APP_P12_PASSWORD \
  OMNIGO_MAC_INSTALLER_P12 OMNIGO_MAC_INSTALLER_P12_PASSWORD OMNIGO_MAC_APP_STORE_PROFILE; do
  [[ -n "${!name:-}" ]] || fail "GitHub Actions sırrı eksik: $name"
done

signing_dir="$(mktemp -d "$RUNNER_TEMP/omnigo-signing.XXXXXX")"
keychain="$signing_dir/omnigo.keychain-db"
profile_path=''
keychains=()
while IFS= read -r listed_keychain; do
  listed_keychain="${listed_keychain#*\"}"
  listed_keychain="${listed_keychain%\"}"
  [[ -n "$listed_keychain" ]] && keychains+=("$listed_keychain")
done < <(security list-keychains -d user)

cleanup() {
  if [[ -n "$profile_path" ]]; then rm -f -- "$profile_path"; fi
  security delete-keychain "$keychain" >/dev/null 2>&1 || true
  if (("${#keychains[@]}" > 0)); then
    security list-keychains -d user -s "${keychains[@]}" >/dev/null 2>&1 || true
  fi
  rm -rf -- "$signing_dir"
}
trap cleanup EXIT

printf '%s' "$OMNIGO_MAC_APP_P12" | base64 -D > "$signing_dir/app.p12"
printf '%s' "$OMNIGO_MAC_INSTALLER_P12" | base64 -D > "$signing_dir/installer.p12"
printf '%s' "$OMNIGO_MAC_APP_STORE_PROFILE" | base64 -D > "$signing_dir/app.provisionprofile"
unset OMNIGO_MAC_APP_P12 OMNIGO_MAC_INSTALLER_P12 OMNIGO_MAC_APP_STORE_PROFILE

security cms -D -i "$signing_dir/app.provisionprofile" > "$signing_dir/profile.plist"
profile_value() {
  /usr/libexec/PlistBuddy -c "Print :$1" "$signing_dir/profile.plist"
}
profile_uuid="$(profile_value UUID)"
[[ "$profile_uuid" =~ ^[[:xdigit:]]{8}(-[[:xdigit:]]{4}){3}-[[:xdigit:]]{12}$ ]] ||
  fail 'Dağıtım profili UUID’si geçersiz.'
[[ "$(profile_value 'TeamIdentifier:0')" == "$OMNIGO_APPLE_TEAM_ID" ]] ||
  fail 'Dağıtım profili seçilen Apple ekibine ait değil.'
bundle_prefix="$(profile_value 'ApplicationIdentifierPrefix:0')"
profile_app_id="$(profile_value 'Entitlements:com.apple.application-identifier')"
[[ -n "$bundle_prefix" && "$profile_app_id" == "$bundle_prefix.com.omnigoglobal.client" ]] ||
  fail 'Dağıtım profili OG İş Ortağım paket kimliğiyle eşleşmiyor.'
[[ "$(profile_value 'Entitlements:com.apple.developer.aps-environment')" == production ]] ||
  fail 'Dağıtım profilinde üretim bildirim izni eksik.'
if profile_value ProvisionedDevices >/dev/null 2>&1 ||
   [[ "$(profile_value ProvisionsAllDevices 2>/dev/null || true)" == true ]]; then
  fail 'Mac App Store Connect dağıtım profilini kullanın.'
fi

profile_dir="$HOME/Library/Developer/Xcode/UserData/Provisioning Profiles"
mkdir -p "$profile_dir"
[[ ! -e "$profile_dir/$profile_uuid.provisionprofile" ]] ||
  fail 'Bu UUID ile bir profil zaten kurulu.'
profile_path="$profile_dir/$profile_uuid.provisionprofile"
cp "$signing_dir/app.provisionprofile" "$profile_path"

keychain_password="$(openssl rand -hex 24)"
security create-keychain -p "$keychain_password" "$keychain"
security set-keychain-settings -lut 3600 "$keychain"
security unlock-keychain -p "$keychain_password" "$keychain"
security import "$signing_dir/app.p12" -k "$keychain" -f pkcs12 \
  -P "$OMNIGO_MAC_APP_P12_PASSWORD" -T /usr/bin/codesign
security import "$signing_dir/installer.p12" -k "$keychain" -f pkcs12 \
  -P "$OMNIGO_MAC_INSTALLER_P12_PASSWORD" -T /usr/bin/productsign -T /usr/bin/productbuild
unset OMNIGO_MAC_APP_P12_PASSWORD OMNIGO_MAC_INSTALLER_P12_PASSWORD
security set-key-partition-list -S apple-tool:,apple: -s -k "$keychain_password" "$keychain" >/dev/null
unset keychain_password
if (("${#keychains[@]}" > 0)); then
  security list-keychains -d user -s "$keychain" "${keychains[@]}"
else
  security list-keychains -d user -s "$keychain"
fi

profile_fingerprint="$(plutil -extract DeveloperCertificates.0 raw -o - "$signing_dir/profile.plist" \
  | base64 -D | openssl dgst -sha1 -r | awk '{print toupper($1)}')"
application_identity="$(security find-identity -v -p codesigning "$keychain" | awk -F '"' -v expected="$profile_fingerprint" \
  'index($1, expected) && $2 ~ /^(Apple Distribution|3rd Party Mac Developer Application|Mac App Distribution): / {print $2}')"
installer_cert="$(security find-identity -v "$keychain" | awk -v team="$OMNIGO_APPLE_TEAM_ID" \
  '/"(3rd Party Mac Developer Installer|Mac Installer Distribution): / && index($0, "(" team ")") {print $2}')"
[[ "$profile_fingerprint" =~ ^[A-F0-9]{40}$ && -n "$application_identity" && "$application_identity" != *$'\n'* ]] ||
  fail 'Mac uygulama dağıtım sertifikası ve özel anahtarı profille eşleşmiyor.'
[[ "$installer_cert" =~ ^[A-F0-9]{40}$ ]] ||
  fail 'Seçilen ekip için tek bir Mac Installer Distribution kimliği gerekli.'
export OMNIGO_MAC_PROFILE_UUID="$profile_uuid"
export OMNIGO_MAC_APP_CERT_NAME="$application_identity"
export OMNIGO_MAC_APP_CERT_SHA1="$profile_fingerprint"
export OMNIGO_MAC_INSTALLER_CERT_SHA1="$installer_cert"

node ios/build-macos.mjs archive
node ios/build-macos.mjs export

shopt -s nullglob
exports=(ios/build/macos/app-store-*/AppStoreInfo.plist)
[[ "${#exports[@]}" -eq 1 ]] || fail 'Doğrulanmış dışa aktarma klasörü tekil değil.'
packages=("${exports[0]%/*}"/*.pkg)
[[ "${#packages[@]}" -eq 1 ]] || fail 'Doğrulanmış Mac yükleme paketi bulunamadı.'
mkdir -p ios/build/macos/upload
cp "${packages[0]}" "${exports[0]}" ios/build/macos/upload/
