# Yii Middleware Change Log

## 1.0.3 under development

- no changes in this release.

## 1.0.2 October 06, 2023

- Enh #103: Add `Access-Control-Expose-Headers: *` to `CorsAllowAll` (@xepozz)
- Bug #105: Fire `SetLocaleEvent` and prepare URL generator in `Locale` before handle request (@vjik)
- Bug #112: Check ignored requests earlier and do not set default locale (@g-rodigy)

## 1.0.1 June 04, 2023

- Chg #95: Remove unused network utilities dependency (@arogachev)
- Bug #96: Fix unexpected redirects from `Locale` middleware on GET requests (@vjik)
- Bug #97: Don't search locale in cookies when `$cookieDuration` is null (@vjik)

## 1.0.0 May 22, 2023

- Initial release.
