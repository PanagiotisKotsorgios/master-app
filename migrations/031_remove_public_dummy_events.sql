-- Remove the two legacy dummy events that were exposed on the public listing.
-- The migration runner may execute this file more than once, so keep the
-- cleanup idempotent and target only the known demo slugs.
DELETE FROM events
WHERE slug IN (
  'demo-kypello-tkd-2026',
  'demo-summer-camp-crete-2026'
);
