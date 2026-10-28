import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:signal_lab/engine/analyzer.dart';
import 'package:signal_lab/screens/info_screens.dart';
import 'package:signal_lab/widgets/common.dart';

void main() {
  testWidgets('Methodology screen explains the rules', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: MethodologyScreen()));
    expect(find.text('How signals work'), findsOneWidget);
    expect(find.textContaining('Trend filter'), findsOneWidget);
  });

  testWidgets('Risk disclaimer is available', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LegalScreen(kind: LegalKind.disclaimer)));
    expect(find.text('Not financial advice'), findsOneWidget);
  });

  testWidgets('Status badge renders its label', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(home: Scaffold(body: Center(child: StatusBadge(SignalStatus.activeBuy)))),
    );
    expect(find.text('BUY ACTIVE'), findsOneWidget);
  });
}
