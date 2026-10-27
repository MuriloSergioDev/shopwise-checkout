# shopwise-checkout

The Shopwise checkout saga (ADR-0004): cart snapshot and quote over REST, then reserve stock → pay → commit stock →
place order → close cart over Kafka commands, with a compensation for every failure and timeout.

Run it from `shopwise-platform`: `tilt up -- --only=identity,pricing,cart,inventory,payments,orders,checkout`, then
start a checkout through the storefront BFF. Conventions and saga rules: `AGENTS.md`.
