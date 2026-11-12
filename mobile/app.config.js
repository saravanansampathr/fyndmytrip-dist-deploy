// One codebase, many branded apps. app.json is the FyndMyTrip app; a
// white-label partner's own app is the same code built with these settings:
//
//   APP_NAME="Acme Travel" APP_ID=com.acmetravel.app \
//   EXPO_PUBLIC_STOREFRONT_HOST=acmetravel.com EXPO_PUBLIC_WEBSITE_URL=https://acmetravel.com \
//   APP_PRIMARY_COLOR="#0055aa" APP_ICON=./branding/acme/icon.png  npx eas-cli build ...
//
// The partner's name, logo and prices then come from their account through
// EXPO_PUBLIC_STOREFRONT_HOST (GET /whitelabel/current), the same way their website works.
module.exports = ({ config }) => {
  const e = process.env;
  const wl = !!e.EXPO_PUBLIC_STOREFRONT_HOST && e.EXPO_PUBLIC_STOREFRONT_HOST !== config.extra.storefrontHost;
  const id = e.APP_ID || (config.ios && config.ios.bundleIdentifier);
  const icon = e.APP_ICON || config.icon;
  return {
    ...config,
    name: e.APP_NAME || config.name,
    slug: e.APP_SLUG || config.slug,
    scheme: e.APP_SCHEME || config.scheme,
    icon,
    ios: { ...config.ios, bundleIdentifier: id },
    android: {
      ...config.android,
      package: id,
      adaptiveIcon: { ...config.android.adaptiveIcon, foregroundImage: e.APP_ADAPTIVE_ICON || (e.APP_ICON ? icon : config.android.adaptiveIcon.foregroundImage) },
    },
    extra: {
      ...config.extra,
      apiBase: e.EXPO_PUBLIC_API_BASE || config.extra.apiBase,
      storefrontHost: e.EXPO_PUBLIC_STOREFRONT_HOST || config.extra.storefrontHost,
      websiteUrl: e.EXPO_PUBLIC_WEBSITE_URL || config.extra.websiteUrl,
      primaryColor: e.APP_PRIMARY_COLOR || config.extra.primaryColor,
      whiteLabel: wl || config.extra.whiteLabel || false,
    },
  };
};
