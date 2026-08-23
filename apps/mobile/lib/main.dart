import 'package:flutter/material.dart';

void main() {
  runApp(const SchoolOsApp());
}

/// Phase 0A primitive: proves the Flutter app boots and can display
/// system status. No business features (SIS, attendance, fees, ...)
/// are implemented in this checkpoint.
class SchoolOsApp extends StatelessWidget {
  const SchoolOsApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'School OS',
      home: Scaffold(
        appBar: AppBar(title: const Text('School OS — Phase 0A')),
        body: const Center(
          child: Text('Architectural foundation only. No modules implemented yet.'),
        ),
      ),
    );
  }
}
