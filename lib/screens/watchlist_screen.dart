import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../engine/analyzer.dart';
import '../services/market_data.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/signal_widgets.dart';
import 'shell.dart';
import 'signals_screen.dart';

enum _Sort { custom, signals, gainers, losers }

class WatchlistScreen extends StatefulWidget {
  const WatchlistScreen({super.key});

  @override
  State<WatchlistScreen> createState() => _WatchlistScreenState();
}

class _WatchlistScreenState extends State<WatchlistScreen> {
  _Sort _sort = _Sort.custom;

  List<String> _sorted(AppState s) {
    final list = List.of(s.watchlist);
    double chg(String sym) => s.analysisOf(sym)?.series.changePct ?? 0;
    int prio(String sym) => s.analysisOf(sym)?.status.priority ?? 9;
    switch (_sort) {
      case _Sort.custom:
        break;
      case _Sort.signals:
        list.sort((a, b) => prio(a).compareTo(prio(b)));
      case _Sort.gainers:
        list.sort((a, b) => chg(b).compareTo(chg(a)));
      case _Sort.losers:
        list.sort((a, b) => chg(a).compareTo(chg(b)));
    }
    return list;
  }

  Future<void> _remove(AppState s, String sym) async {
    final i = await s.removeSymbol(sym);
    if (!mounted || i < 0) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text('$sym removed from watchlist'),
        action: SnackBarAction(label: 'Undo', onPressed: () => s.insertSymbol(i, sym)),
      ));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final symbols = _sorted(s);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Watchlist'),
        actions: [
          TimeframeToggle(value: s.timeframe, onChanged: s.setTimeframe),
          PopupMenuButton<_Sort>(
            tooltip: 'Sort',
            icon: const Icon(Icons.sort_rounded),
            initialValue: _sort,
            onSelected: (v) => setState(() => _sort = v),
            itemBuilder: (_) => const [
              PopupMenuItem(value: _Sort.custom, child: Text('My order')),
              PopupMenuItem(value: _Sort.signals, child: Text('Signals first')),
              PopupMenuItem(value: _Sort.gainers, child: Text('Top gainers')),
              PopupMenuItem(value: _Sort.losers, child: Text('Top losers')),
            ],
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const SearchScreen())),
        backgroundColor: AppColors.primary,
        foregroundColor: const Color(0xFF042F2E),
        icon: const Icon(Icons.add_rounded),
        label: const Text('Add stock', style: TextStyle(fontWeight: FontWeight.w700)),
      ),
      body: RefreshIndicator(
        onRefresh: () => s.refresh(force: true),
        child: symbols.isEmpty
            ? ListView(children: [
                MessageView(
                  icon: Icons.star_border_rounded,
                  title: 'Your watchlist is empty',
                  message: 'Add stocks you follow and Stock Trading Signals will scan them for setups.',
                  actionLabel: 'Add stocks',
                  onAction: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const SearchScreen())),
                ),
              ])
            : ListView.builder(
                padding: const EdgeInsets.only(top: Gap.xs, bottom: 96),
                itemCount: symbols.length + 1,
                itemBuilder: (context, i) {
                  if (i == 0) {
                    return Padding(
                      padding: const EdgeInsets.fromLTRB(Gap.lg + 4, 0, Gap.lg, Gap.xs),
                      child: Row(
                        children: [
                          FreshnessLine(updated: s.lastUpdated, refreshing: s.refreshing),
                          const Spacer(),
                          const Text('Swipe left to remove', style: TextStyle(color: AppColors.faint, fontSize: 11)),
                        ],
                      ),
                    );
                  }
                  final sym = symbols[i - 1];
                  final st = s.stateOf(sym);
                  return Dismissible(
                    key: ValueKey('w-$sym'),
                    direction: DismissDirection.endToStart,
                    background: Container(
                      alignment: Alignment.centerRight,
                      margin: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 6),
                      padding: const EdgeInsets.only(right: Gap.xl),
                      decoration: BoxDecoration(
                        color: AppColors.bear.withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(Gap.radius),
                      ),
                      child: const Icon(Icons.delete_outline_rounded, color: AppColors.bear),
                    ),
                    onDismissed: (_) => _remove(s, sym),
                    child: WatchRow(
                      symbol: sym,
                      analysis: st.analysis,
                      error: st.error,
                      loading: st.loading,
                      onTap: () => openStock(context, sym),
                    ),
                  );
                },
              ),
      ),
    );
  }
}

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  static const _popular = ['AAPL', 'MSFT', 'NVDA', 'TSLA', 'AMZN', 'META', 'GOOGL', 'AMD', 'NFLX', 'JPM', 'V', 'KO', 'DIS', 'PLTR', 'SPY', 'QQQ'];

  final _ctrl = TextEditingController();
  Timer? _debounce;
  List<SymbolSearchResult> _results = [];
  bool _loading = false;
  String? _error;
  int _req = 0;

  @override
  void dispose() {
    _debounce?.cancel();
    _ctrl.dispose();
    super.dispose();
  }

  void _onChanged(String q) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () => _search(q));
    setState(() {});
  }

  Future<void> _search(String q) async {
    final id = ++_req;
    if (q.trim().isEmpty) {
      setState(() {
        _results = [];
        _loading = false;
        _error = null;
      });
      return;
    }
    setState(() => _loading = true);
    try {
      final r = await context.read<AppState>().market.search(q);
      if (!mounted || id != _req) return;
      setState(() {
        _results = r;
        _error = null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted || id != _req) return;
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final q = _ctrl.text.trim();
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Padding(
          padding: const EdgeInsets.only(right: Gap.lg),
          child: TextField(
            controller: _ctrl,
            autofocus: true,
            textCapitalization: TextCapitalization.characters,
            onChanged: _onChanged,
            decoration: InputDecoration(
              hintText: 'Search company or ticker',
              prefixIcon: const Icon(Icons.search_rounded),
              isDense: true,
              suffixIcon: q.isEmpty
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.close_rounded),
                      onPressed: () {
                        _ctrl.clear();
                        _onChanged('');
                      },
                    ),
            ),
          ),
        ),
      ),
      body: q.isEmpty
          ? ListView(
              padding: const EdgeInsets.all(Gap.lg),
              children: [
                const Text('Popular', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: Gap.md),
                Wrap(
                  spacing: Gap.sm,
                  runSpacing: Gap.sm,
                  children: [
                    for (final p in _popular)
                      FilterChip(
                        label: Text(p),
                        selected: s.inWatchlist(p),
                        avatar: s.inWatchlist(p) ? const Icon(Icons.check, size: 16) : null,
                        onSelected: (_) => s.inWatchlist(p) ? s.removeSymbol(p) : s.addSymbol(p),
                      ),
                  ],
                ),
                const SizedBox(height: Gap.xl),
                const InfoNote('US-listed stocks and ETFs work best. Tickers follow Yahoo Finance format (e.g. BRK-B).'),
              ],
            )
          : _loading && _results.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : _error != null
                  ? MessageView(icon: Icons.wifi_off_rounded, title: 'Search failed', message: _error!, color: AppColors.bear)
                  : _results.isEmpty
                      ? MessageView(
                          icon: Icons.search_off_rounded,
                          title: 'No matches',
                          message: 'Try the exact ticker, or add “$q” directly.',
                          actionLabel: 'Add $q',
                          onAction: () async {
                            await s.addSymbol(q);
                            if (context.mounted) Navigator.pop(context);
                          },
                        )
                      : ListView.separated(
                          itemCount: _results.length,
                          separatorBuilder: (_, __) => const Divider(indent: 72),
                          itemBuilder: (context, i) {
                            final r = _results[i];
                            final added = s.inWatchlist(r.symbol);
                            return ListTile(
                              contentPadding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 2),
                              leading: SymbolAvatar(r.symbol),
                              title: Text(r.symbol, style: const TextStyle(fontWeight: FontWeight.w700)),
                              subtitle: Text(
                                '${r.name} · ${r.exchange}${r.type == 'ETF' ? ' · ETF' : ''}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: AppColors.muted, fontSize: 12),
                              ),
                              trailing: IconButton.filledTonal(
                                tooltip: added ? 'Remove' : 'Add to watchlist',
                                onPressed: () => added ? s.removeSymbol(r.symbol) : s.addSymbol(r.symbol),
                                icon: Icon(added ? Icons.check_rounded : Icons.add_rounded,
                                    color: added ? AppColors.bull : AppColors.text),
                              ),
                              onTap: () => openStock(context, r.symbol),
                            );
                          },
                        ),
    );
  }
}
