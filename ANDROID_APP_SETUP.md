# KooPal Android App Setup

This project now includes a Capacitor wrapper so you can turn the current PHP site into an Android app for your own devices.

## Current default URL

The Android app loads this URL by default:

`http://10.0.2.2/manwha_reader/`

That works for the Android emulator when XAMPP is running on the same PC.

## For a real phone

Your phone must be on the same Wi-Fi as your computer, and XAMPP/Apache must be running.

Set `CAP_SERVER_URL` to your computer's LAN IP before syncing or running:

```powershell
$env:CAP_SERVER_URL="http://192.168.1.50/manwha_reader/"
npx cap sync android
```

Replace `192.168.1.50` with your actual computer IP.

## Useful commands

Install dependencies:

```powershell
npm.cmd install
```

Create/update Android project:

```powershell
npx cap add android
npx cap sync android
```

Open in Android Studio:

```powershell
npx cap open android
```

## Notes

- `10.0.2.2` is for Android emulator only.
- Physical phones cannot access your PC using `localhost`.
- If images or API calls are blocked, make sure Apache is reachable from your phone and Windows firewall allows it.
