-- ================================================================
-- 018_soften_reminder_templates.sql
-- Refreshes ALREADY-SEEDED notification_rules for every school so
-- they use the softer, warmer default template with the
-- "κηδεμόνα / αθλητή" greeting and "Σας ευχαριστούμε πολύ" closing.
--
-- Idempotent — matches the exact previous body_tpl. Any school that
-- has already customised its template will NOT be touched, because
-- their body_tpl string won't match either variant below.
-- ================================================================

-- Variant A: the original harsh text (pre-softening commit)
UPDATE notification_rules
   SET body_tpl = 'Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΌποτε σας εξυπηρετεί, μπορούμε να την τακτοποιήσουμε. Αν χρειάζεστε κάτι ή θέλετε να το συζητήσουμε, είμαστε στη διάθεσή σας.\n\nΣας ευχαριστούμε πολύ,\n{{school_name}}'
 WHERE body_tpl = 'Αγαπητέ/ή κηδεμόνα,\n\nΣας ενημερώνουμε ότι υπάρχει εκκρεμής οφειλή για τη συνδρομή του/της {{athlete_name}}.\nΟφειλόμενο ποσό: {{amount}}€\n\nΠαρακαλούμε τακτοποιήστε την πληρωμή το συντομότερο δυνατό.\n\nΜε εκτίμηση,\n{{school_name}}';

-- Variant B: the interim soft text (between commits 5d8a3a7 and this one)
UPDATE notification_rules
   SET body_tpl = 'Αγαπητέ/ή κηδεμόνα / αθλητή,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΌποτε σας εξυπηρετεί, μπορούμε να την τακτοποιήσουμε. Αν χρειάζεστε κάτι ή θέλετε να το συζητήσουμε, είμαστε στη διάθεσή σας.\n\nΣας ευχαριστούμε πολύ,\n{{school_name}}'
 WHERE body_tpl = 'Αγαπητέ/ή κηδεμόνα,\n\nΘα θέλαμε φιλικά να σας υπενθυμίσουμε ότι εκκρεμεί η συνδρομή του/της {{athlete_name}}.\nΠοσό: {{amount}}€\n\nΌποτε σας εξυπηρετεί, μπορούμε να την τακτοποιήσουμε. Αν χρειάζεστε κάτι ή θέλετε να το συζητήσουμε, είμαστε στη διάθεσή σας.\n\nΕυχαριστούμε πολύ,\n{{school_name}}';
