// One-time Android setup after `flutter create .`
//   dart run tool/setup.dart
//
// 1. Adds INTERNET + POST_NOTIFICATIONS permissions and the app label.
// 2. Enables core library desugaring (required by flutter_local_notifications).
import 'dart:io';

void main() {
  _manifest();
  _gradle();
  stdout.writeln('\nSetup complete. Next: flutter pub get && dart run flutter_launcher_icons');
}

void _manifest() {
  final f = File('android/app/src/main/AndroidManifest.xml');
  if (!f.existsSync()) _fail('AndroidManifest.xml not found. Run "flutter create ." first.');
  var xml = f.readAsStringSync();
  for (final perm in ['android.permission.INTERNET', 'android.permission.POST_NOTIFICATIONS']) {
    if (!xml.contains(perm)) {
      xml = xml.replaceFirstMapped(
        RegExp(r'<manifest[^>]*>'),
        (m) => '${m[0]}\n    <uses-permission android:name="$perm"/>',
      );
    }
  }
  xml = xml.replaceFirst(RegExp(r'android:label="[^"]*"'), 'android:label="Stock Trading Signals"');
  // Android 11+: allow opening https links (chat, coffee, store) in other apps.
  if (!xml.contains('android.intent.action.VIEW" />\n            <data android:scheme="https"')) {
    const q = '    <queries>\n        <intent>\n            <action android:name="android.intent.action.VIEW" />\n'
        '            <data android:scheme="https" />\n        </intent>\n    </queries>\n';
    xml = xml.replaceFirst('</manifest>', '$q</manifest>');
  }
  f.writeAsStringSync(xml);
  stdout.writeln('✓ AndroidManifest.xml: permissions + label');
}

void _gradle() {
  final kts = File('android/app/build.gradle.kts');
  final groovy = File('android/app/build.gradle');
  if (kts.existsSync()) {
    var s = kts.readAsStringSync();
    if (!s.contains('isCoreLibraryDesugaringEnabled')) {
      s = s.replaceFirst('compileOptions {', 'compileOptions {\n        isCoreLibraryDesugaringEnabled = true');
    }
    if (!s.contains('desugar_jdk_libs')) {
      s += '\ndependencies {\n    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")\n}\n';
    }
    kts.writeAsStringSync(s);
    stdout.writeln('✓ build.gradle.kts: desugaring enabled');
  } else if (groovy.existsSync()) {
    var s = groovy.readAsStringSync();
    if (!s.contains('coreLibraryDesugaringEnabled')) {
      s = s.replaceFirst('compileOptions {', 'compileOptions {\n        coreLibraryDesugaringEnabled true');
    }
    if (!s.contains('desugar_jdk_libs')) {
      s += "\ndependencies {\n    coreLibraryDesugaring 'com.android.tools:desugar_jdk_libs:2.1.4'\n}\n";
    }
    groovy.writeAsStringSync(s);
    stdout.writeln('✓ build.gradle: desugaring enabled');
  } else {
    _fail('android/app/build.gradle(.kts) not found. Run "flutter create ." first.');
  }
}

Never _fail(String msg) {
  stderr.writeln('✗ $msg');
  exit(1);
}
