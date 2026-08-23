import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:school_os_mobile/main.dart';

void main() {
  testWidgets('renders the Phase 0A placeholder screen', (WidgetTester tester) async {
    await tester.pumpWidget(const SchoolOsApp());

    expect(find.text('School OS — Phase 0A'), findsOneWidget);
    expect(find.textContaining('No modules implemented yet'), findsOneWidget);
  });
}
