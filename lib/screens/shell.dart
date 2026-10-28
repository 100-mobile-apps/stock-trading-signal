import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/app_state.dart';
import '../widgets/support.dart';
import 'dashboard_screen.dart';
import 'results_screen.dart';
import 'settings_screen.dart';
import 'signals_screen.dart';
import 'stock_detail_screen.dart';
import 'watchlist_screen.dart';

/// Opens the full stock page from anywhere.
void openStock(BuildContext context, String symbol) {
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => StockDetailScreen(symbol: symbol)));
}

class Shell extends StatefulWidget {
  const Shell({super.key});

  @override
  State<Shell> createState() => ShellState();
}

class ShellState extends State<Shell> with WidgetsBindingObserver {
  int _tab = 0;

  void goTo(int tab) => setState(() => _tab = tab);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) Support.maybeShowPopup(context);
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Refresh server data when the user comes back to the app.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) context.read<AppState>().refreshServer();
  }

  @override
  Widget build(BuildContext context) {
    final state = context.watch<AppState>();
    final newCount = state.liveSignals.where((s) => s.isPending).length;
    Widget badge(IconData icon) => Badge(isLabelVisible: newCount > 0, label: Text('$newCount'), child: Icon(icon));
    return Scaffold(
      body: IndexedStack(
        index: _tab,
        children: [
          DashboardScreen(onNavigate: goTo),
          const SignalsScreen(),
          const WatchlistScreen(),
          const ResultsScreen(),
          const SettingsScreen(),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: goTo,
        destinations: [
          const NavigationDestination(
            icon: Icon(Icons.space_dashboard_outlined),
            selectedIcon: Icon(Icons.space_dashboard_rounded),
            label: 'Home',
          ),
          NavigationDestination(
            icon: badge(Icons.bolt_outlined),
            selectedIcon: badge(Icons.bolt_rounded),
            label: 'Signals',
          ),
          const NavigationDestination(
            icon: Icon(Icons.star_border_rounded),
            selectedIcon: Icon(Icons.star_rounded),
            label: 'Watchlist',
          ),
          const NavigationDestination(
            icon: Icon(Icons.query_stats_outlined),
            selectedIcon: Icon(Icons.query_stats_rounded),
            label: 'Results',
          ),
          const NavigationDestination(
            icon: Icon(Icons.settings_outlined),
            selectedIcon: Icon(Icons.settings_rounded),
            label: 'Settings',
          ),
        ],
      ),
    );
  }
}
