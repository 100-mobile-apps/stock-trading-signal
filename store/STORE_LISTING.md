# Google Play listing & ranking plan (ASO)

## 1. App name / title (the #1 ranking factor): 30 characters max

**App name: `Stock Trading Signals`** (21 characters). This is the Play Store title, the name under the
icon, and the name inside the app and in emails.

- It is an exact match for the main search phrase *"stock trading signals"*, and Play also indexes
  each word, so it ranks for *stock signals*, *trading signals* and *stock trading*.
- Optional: Play allows 30 characters, so `Stock Trading Signals & Alerts` (30) would add the
  *alerts* keyword. Only change it if you're happy for the store title to differ slightly from the
  app name.
- **Why "Free" is NOT in the title:** Google Play's metadata policy bans promotional words such as
  **free, best, #1, top, new, sale, no ads** in the app title, icon and developer name. Listings that
  break this are rejected or hidden. "Free" goes at the start of the short description (allowed), and
  Play still matches it for searches like *"free stock trading signals"*.
- On some phones the name under the icon may be shortened to "Stock Trading…". That's normal and
  doesn't affect search.

## 2. Short description: 80 characters max
`Free stock trading signals, buy alerts & a real tracked win/loss record` (78)

## 3. Full description
See `fastlane/metadata/android/en-US/full_description.txt` (≈2,400 of 4,000 characters).
Keywords appear naturally 2–4 times each. Play penalizes keyword stuffing and repetition, so don't
add keyword lists.

**Keyword set:** free stock signals · stock signals · trading signals · stock alerts · buy signals ·
stop-loss · take profit · swing trading · day trading · stock market · watchlist · track record ·
position size calculator · S&P 500 · Nasdaq · Tesla · Apple · NVIDIA

## 4. Store settings
- **Developer name:** Swiftmynd Technologies Ltd
- **Package name:** `com.swiftmynd.signal_lab`
- **Category:** Finance · **Tags:** Stocks, Investing, Trading
- **Contact email:** your support mailbox (the same one the server sends from)
- **Privacy policy URL:** `https://stocksignal.bulksend.top/privacy`
- **Account deletion URL** (required for apps with accounts): `https://stocksignal.bulksend.top/delete-account`
- **Content rating:** complete the questionnaire. No gambling or user-generated content; the app
  gives financial information.
- **Financial features declaration:** choose *"My app doesn't provide any financial features"*
  unless you add trading, loans or payments. It shows educational market information only, with no
  brokerage or custody. Keep the "not financial advice" disclaimer visible, as it already is in the app.
- **Ads:** "No, my app does not contain ads."
- **In-app purchases:** Yes. Premium subscriptions `premium_monthly` and `premium_yearly` (Google Play Billing).
  The app never links to the website checkout (Play payments policy). Broker links are affiliate links,
  disclosed on the Brokers screen.
- **Data safety form:**
  - Collected: *Email address, Name* (account management), *Purchase history* (Premium subscription
    status, for app functionality; payments are processed by Google Play), *App activity: in-app actions*
    (watchlist and settings, for app functionality), *Device or other IDs* (push token, for app functionality).
  - Encrypted in transit: **Yes**. Users can request deletion: **Yes** (in app + web URL).
  - Shared with third parties: **No** (Firebase is a service provider, not "sharing").

## 5. Graphics
- **Icon:** `store/playstore_icon_512.png`: 512×512 PNG, 32-bit with alpha, 15 KB (Play's limit is 1 MB). Upload it square; Play rounds the corners itself.
- **Feature graphic (1024×500):** `store/feature_graphic.png`
- **Phone screenshots:** `store/screenshots/` in this order (the first 3 matter most):
  1. Home: live signals + results
  2. Signal detail: entry, stop, targets
  3. Results: daily / weekly / monthly verified record
  4. Stock chart with signals
  5. Alerts (push + email)
  6. Position size calculator
  7. Watchlist scanned hourly
  8. Every win & loss recorded (signal history)
  All screenshots are 1080×1920 PNG (9:16), which is within Play's limits (320–3840 px per side).
  The included screenshots already have captions and use demo data. Once your live record has a few
  weeks of history, retake them from the real app so the numbers shown are your real results.
  Add a short caption on each ("Free stock signals with stop-loss & targets", "Every result tracked:
  wins and losses", …). Captions inside screenshots are allowed; just avoid "#1" or "best" claims.

## 6. Ranking levers after launch
1. **Ratings:** the in-app pop-up asks happy users to rate (after the 3rd open, at most every 5 days,
   using Google's native review dialog). Rating volume and average are strong ranking signals.
2. **Retention:** push alerts bring users back, and Play rewards apps people keep and open.
3. **Update often** (monthly) and put keywords in "What's new".
4. **Store listing experiments** (Play Console → Grow → Store listing experiments): A/B test the
   title variants above and the first screenshot.
5. **Custom store listings** for Nigeria/Africa or other countries with localized wording.
6. Link the public track-record page from social posts. External installs and backlinks help.
