import 'dart:async';

import 'package:in_app_purchase/in_app_purchase.dart';

import '../config.dart';
import 'api_client.dart';

/// Google Play subscriptions for Premium.
///
/// Play policy: digital features bought inside the app must use Google Play Billing, so the app
/// never links to the website checkout. Every purchase is verified by our server
/// (POST /billing/google/verify), which unlocks Premium on the same account everywhere.
class BillingService {
  BillingService(this.api);

  final ApiClient api;
  final InAppPurchase _iap = InAppPurchase.instance;
  StreamSubscription<List<PurchaseDetails>>? _sub;

  /// Products keyed by Play product id (empty until [load] succeeds).
  final Map<String, ProductDetails> products = {};
  bool available = false;

  /// Called after the server confirms a purchase, with the fresh Premium status JSON.
  void Function(Map<String, dynamic> status)? onVerified;

  /// Called with a user-facing error message.
  void Function(String message)? onError;

  /// Called when a purchase flow ends (success, cancel or error) so the UI can stop spinning.
  void Function()? onIdle;

  Future<void> init() async {
    _sub ??= _iap.purchaseStream.listen(_onPurchases, onError: (_) => onIdle?.call());
  }

  Future<void> load() async {
    available = await _iap.isAvailable();
    if (!available) return;
    final res = await _iap.queryProductDetails(AppConfig.premiumProductIds);
    products
      ..clear()
      ..addEntries(res.productDetails.map((p) => MapEntry(p.id, p)));
  }

  /// Starts the Play purchase sheet. [accountId] ties the purchase to this user (checked by the server).
  Future<void> buy(String productId, String accountId) async {
    final p = products[productId];
    if (p == null) {
      onError?.call('This plan is not available on Google Play right now. Please try again later.');
      onIdle?.call();
      return;
    }
    final ok = await _iap.buyNonConsumable(
      purchaseParam: PurchaseParam(productDetails: p, applicationUserName: accountId),
    );
    if (!ok) onIdle?.call();
  }

  /// Restores existing subscriptions (e.g. after reinstalling or on a new phone).
  Future<void> restore() => _iap.restorePurchases();

  Future<void> _onPurchases(List<PurchaseDetails> list) async {
    for (final p in list) {
      switch (p.status) {
        case PurchaseStatus.pending:
          break;
        case PurchaseStatus.purchased:
        case PurchaseStatus.restored:
          try {
            final status = await api.post('/billing/google/verify', {
              'product_id': p.productID,
              'purchase_token': p.verificationData.serverVerificationData,
            });
            onVerified?.call((status as Map).cast<String, dynamic>());
          } on ApiException catch (e) {
            onError?.call(e.message);
          }
          if (p.pendingCompletePurchase) await _iap.completePurchase(p);
          onIdle?.call();
        case PurchaseStatus.error:
          onError?.call(p.error?.message ?? 'The purchase did not complete.');
          if (p.pendingCompletePurchase) await _iap.completePurchase(p);
          onIdle?.call();
        case PurchaseStatus.canceled:
          onIdle?.call();
      }
    }
  }

  void dispose() => _sub?.cancel();
}
