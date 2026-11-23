import 'package:flutter/material.dart';

import '../core/theme.dart';
import '../config.dart';
import '../widgets/common.dart';

class _Doc extends StatelessWidget {
  const _Doc({required this.title, required this.sections});
  final String title;
  final List<(String, String)> sections;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(title)),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.sm, Gap.xl, 40),
          children: [
            for (final (h, body) in sections) ...[
              const SizedBox(height: Gap.lg),
              Text(h, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
              const SizedBox(height: Gap.sm),
              Text(body, style: const TextStyle(color: Color(0xFFC3CBDA), height: 1.55, fontSize: 14.5)),
            ],
          ],
        ),
      );
}

class MethodologyScreen extends StatelessWidget {
  const MethodologyScreen({super.key});

  @override
  Widget build(BuildContext context) => const _Doc(
        title: 'How signals work',
        sections: [
          (
            'The strategy in one sentence',
            'Trade only in the direction of the trend, enter when momentum turns back in that direction, '
                'and always define the exit before entering.'
          ),
          (
            '1 · Trend filter',
            'A BUY requires the price and the 50-period EMA to be above the 200-period EMA (an established '
                'uptrend). A SELL requires both to be below it.'
          ),
          (
            '2 · Momentum trigger',
            'The MACD line must cross its signal line in the trend’s direction on a closed bar. '
                'Signals are computed on completed bars only, so they never appear and then disappear.'
          ),
          (
            '3 · Filters',
            'RSI must be 40–70 for buys (30–60 for sells) to avoid chasing overextended moves. '
                'Strength rises when volume is above its 20-bar average and RSI sits in its best momentum zone.'
          ),
          (
            '4 · Exits',
            'Stop-loss: 1.5 × ATR from entry. Target 1: 1.5 × ATR (a sensible place to take partial profits). '
                'Target 2: 3 × ATR, a 2 : 1 reward-to-risk ratio. If neither is hit within 20 bars, the trade is closed.'
          ),
          (
            '5 · Position size',
            'Shares = (account × risk %) ÷ (entry − stop). A stopped-out trade then loses about your chosen '
                'risk, no matter how volatile the stock is.'
          ),
          (
            'How the track record is calculated',
            '• Entries fill at the next bar’s open, never at the signal price.\n'
                '• If the stop and the target are both touched in one bar, the stop counts first.\n'
                '• Gaps past a level fill at the open, which can be worse than the level.\n'
                '• 0.1% round-trip costs are deducted from every trade.\n'
                '• One position per stock at a time.\n'
                '• Results are compared with simply buying and holding.'
          ),
          (
            'What to expect',
            'With a 2 : 1 reward-to-risk, the strategy can be profitable with a win rate around 35–45%. '
                'Losing streaks of 5–8 trades are normal. It works best in trending markets and struggles in '
                'choppy, sideways ones. Past performance does not guarantee future results.'
          ),
        ],
      );
}

enum LegalKind { disclaimer, privacy }

class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key, required this.kind});
  final LegalKind kind;

  @override
  Widget build(BuildContext context) => kind == LegalKind.disclaimer
      ? const _Doc(
          title: 'Risk disclaimer',
          sections: [
            (
              'Not financial advice',
              'Stock Trading Signals provides educational information generated automatically from public market data '
                  'by a fixed set of rules. It is not investment, financial, legal or tax advice, and it is not a '
                  'recommendation to buy or sell any security. You alone are responsible for your trading decisions.'
            ),
            (
              'Trading involves risk',
              'Trading stocks involves substantial risk of loss and is not suitable for everyone. You can lose some '
                  'or all of the money you trade. No signal, indicator or strategy is profitable on every trade, and '
                  'past or backtested performance does not guarantee future results.'
            ),
            (
              'Backtest limitations',
              'Backtested results are hypothetical. They are calculated in hindsight, may not reflect real fills, '
                  'liquidity, commissions or taxes, and are not a record of actual trading.'
            ),
            (
              'Data',
              'Prices come from free public sources, may be delayed about 15 minutes, and can occasionally be '
                  'inaccurate or unavailable. Always confirm prices with your broker before trading.'
            ),
          ],
        )
      : const _Doc(
          title: 'Privacy',
          sections: [
            (
              'What we collect',
              'Your name and email (or your Google account’s name and email if you sign in with Google), a securely '
                  'hashed password, your risk settings, watchlist, notification preferences, the alerts we sent you, '
                  'and a push-notification token for each of your devices.'
            ),
            (
              'Why',
              'Only to run the service: to sign you in, scan your watchlist, send push and email alerts and reports, '
                  'and show your results. We never sell your data, show ads or use tracking/advertising SDKs.'
            ),
            (
              'Where it is stored',
              'On our own server, over encrypted HTTPS connections. Sign-in tokens are kept in your phone’s secure '
                  'storage (Keychain / Keystore). Push notifications are delivered through Google Firebase Cloud Messaging.'
            ),
            (
              'Market data',
              'Charts and prices come from Yahoo Finance’s public endpoints. Those requests contain only ticker symbols.'
            ),
            (
              'Your choices',
              'Turn any alert or email off in Settings → Notifications. Delete your account anytime in Settings → Account; '
                  'this permanently erases your account and all its data from our server.'
            ),
          ],
        );
}

class AboutCard extends StatelessWidget {
  const AboutCard({super.key});

  @override
  Widget build(BuildContext context) => const Column(
        children: [
          BrandMark(size: 56),
          SizedBox(height: Gap.md),
          Text('Stock Trading Signals', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          Text('Version 1.0.0', style: TextStyle(color: AppColors.muted, fontSize: 12)),
          SizedBox(height: 4),
          Text('by ${AppConfig.developerName}', style: TextStyle(color: AppColors.faint, fontSize: 12)),
        ],
      );
}
