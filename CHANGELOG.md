# Yii Middleware Change Log

## 1.1.1 under development

- Chg #136: Add PHP 8.5 version support (@rustamwin)

## 1.1.0 June 10, 2025

- Chg #129: Bump PHP minimal version to 8.1 and refactor code to use new features (@dagpro)
- Chg #129: Change PHP constraint in `composer.json` to `8.1 - 8.4` (@dagpro)
- Chg #129: Bump `yiisoft/router` version to `^4.0` (@dagpro)
- Chg #129: Bump `yiisoft/session` version to `^3.0` (@dagpro)
- Chg #130: Bump minimal version of `yiisoft/cookie` to `^1.2.3` (@vjik)
- Chg #132: Mark `CorsAllowAll`, `ForceSecureConnection`, `HttpCache` and `TagRequest` middlewares as deprecated (@vjik)
- Enh #130: Allow to use PSR-20 clock interface to get current time into `Locale` middleware (@vjik)
- Bug #129: Explicitly mark nullable parameters (@dagpro)

## 1.0.4 September 03, 2024

- Enh #121: Add `network-utilities` dependency and use it instead of `validator` for `IpFilter` (@arogachev)
- Enh #121: Add support for `validator` of version 2.0, mark it as deprecated (@arogachev)

## 1.0.3 June 06, 2024

- Enh #117: Add support for `psr/http-message` version `^2.0` (@bautrukevich)

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
