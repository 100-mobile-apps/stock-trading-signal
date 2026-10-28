# Build a signed Android App Bundle on another PC

Use the **same upload key** for every Google Play update. Do not create a new
keystore for this app. The release build reads `SIGNALLAB_UPLOAD_KEYSTORE` and
`SIGNALLAB_UPLOAD_PASSWORD` from the build process environment and uses the
`signal-lab-upload` key alias (see `android/app/build.gradle.kts`).

## Back up the key on the original PC

1. Copy `C:\Users\Admin\.android\signal-lab-upload.jks` to secure, private
   backup storage. Do not put it in this repository, the Android app, or a
   public cloud folder.
2. On the original PC, run the following in PowerShell to display the password.
   Do this privately, then save it in a password manager:

   ```powershell
   (Import-Clixml "$env:USERPROFILE\.android\signal-lab-upload-password.xml").GetNetworkCredential().Password
   ```

   This command displays the password in plain text on screen; do not share
   the output or paste it into source files. The `.xml` file is encrypted for
   the original Windows user and **cannot be used to recover the password on
   another PC**. Back up both the `.jks` file and the password separately.

## Build on the new Windows PC

1. Install the Flutter SDK, Android SDK, and a compatible JDK. Check the
   installation with `flutter doctor -v`.
2. Copy the project to the new PC. Make sure
   `android/app/google-services.json` is present for the same Firebase app.
3. Place the backed-up keystore at
   `$env:USERPROFILE\.android\signal-lab-upload.jks`. If you choose another
   location, use its full path in `SIGNALLAB_UPLOAD_KEYSTORE` below.
4. In PowerShell, from the project root, run:

   ```powershell
   $env:SIGNALLAB_UPLOAD_KEYSTORE = "$env:USERPROFILE\.android\signal-lab-upload.jks"
   $env:SIGNALLAB_UPLOAD_PASSWORD = (Get-Credential -UserName 'signal-lab-upload' -Message 'Enter upload keystore password').GetNetworkCredential().Password

   flutter pub get
   flutter build appbundle --release `
     --dart-define=API_BASE_URL=https://signalab.bulksend.top `
     --dart-define=GOOGLE_SERVER_CLIENT_ID=222013884908-n9pn4sgjipl20s3ilk5ttcl4fmkri7mm.apps.googleusercontent.com

   Remove-Item Env:SIGNALLAB_UPLOAD_PASSWORD
   Remove-Item Env:SIGNALLAB_UPLOAD_KEYSTORE
   ```

   `Get-Credential` prompts for the password without placing the password in
   the command or PowerShell history. Use the same terminal for the build:
   environment variables set in one terminal do not automatically appear in
   another. The bundle is written to
   `build/app/outputs/bundle/release/app-release.aab`.

## Verify before uploading

Use the `keytool` from the installed JDK or Android Studio. For example:

```powershell
& 'C:\Program Files\Android\Android Studio\jbr\bin\keytool.exe' -printcert -jarfile 'build\app\outputs\bundle\release\app-release.aab'
```

The AAB must show `CN=SignalLab Upload`, **not** `CN=Android Debug`.
Its upload certificate SHA-1 is:

```text
DC:A7:EC:5E:52:2D:55:D2:99:0D:6A:95:CA:86:A5:8F:FE:9E:75:37
```

Add this SHA-1 to the Android app in Firebase for Google Sign-In. If Google
Play App Signing is enabled, also add the distinct **app-signing** SHA-1 from
Play Console > App integrity. Keep the keystore and password backups secure;
without them, future updates cannot use this upload key unless an eligible
Google Play upload-key reset is completed.
