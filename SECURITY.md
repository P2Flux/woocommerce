# Security policy

## Reporting a vulnerability

Please report security issues privately to **contact@p2flux.com**. Do not open a public issue,
pull request or discussion for anything that could put funds, merchants or payers at risk.

A useful report includes what is affected (repository, contract address or endpoint), how to
reproduce it, and what you believe an attacker could achieve. If you have a proof of concept,
describe it rather than running it against anything live.

We aim to acknowledge a report within five business days, keep you informed while we investigate,
and tell you when a fix has shipped.

## Ground rules

- **Do not publicly disclose** an issue before we have had a reasonable time to fix it. We will
  agree a disclosure date with you.
- **Do not exploit real users or real funds.** Test with your own wallets, your own integrations
  and the test environment (Base Sepolia, `api-test.p2flux.com`) wherever possible.
- **No destructive testing.** Do not delete, corrupt or alter data that is not yours.
- **No denial of service.** Do not flood the API, the relayer or the hosted checkout, and do not
  attempt to exhaust relayer funds or rate limits.
- No social engineering, phishing or physical attacks against P2Flux staff, merchants or payers.

Research carried out in good faith within these rules will not be pursued legally by P2Flux.

## Scope

In scope: the code in the public P2Flux repositories, the deployed P2Flux smart contracts, the
P2Flux API and the hosted checkout.

Out of scope: third-party wallets, blockchain networks, RPC providers and token contracts that
P2Flux does not operate; merchants' own websites; and findings that require a compromised device or
wallet on the victim's side.

## Rewards

P2Flux does not currently operate a paid bug-bounty program. Reports are genuinely appreciated and
credited on request, but payment is not promised.
