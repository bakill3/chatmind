<?php
// NOTE: This file RETURNS the array (so include() gets the data).
// Also fixed a stray control char in "Meditations" summary.

return [
  '48_laws' => [
    'title' => 'The 48 Laws of Power',
    'summary' => 'Be strategic; protect status; use timing and framing; concede small to win large; mirror to disarm; avoid over-explaining.',
    'keywords' => ['strategy','timing','framing','status','leverage'],
    'weight' => 0.75,
  ],
  'art_of_war' => [
    'title' => 'The Art of War',
    'summary' => 'Win before conflict; shape terrain and tempo; appear weak when strong; pick battles; conserve energy; misdirect.',
    'keywords' => ['tempo','terrain','timing','strategy'],
    'weight' => 0.55,
  ],

  'meditations' => [
    'title' => 'Meditations (Stoicism)',
    'summary' => 'Respond with calm clarity; focus on what is in your control; avoid catastrophizing; value virtue over validation; measured tone.',
    'keywords' => ['calm','clarity','control','measured'],
    'weight' => 0.8,
  ],
  'republic' => [
    'title' => 'The Republic',
    'summary' => 'Seek truth via questions; clarify definitions; structured reasoning; aim for fairness and long-term harmony.',
    'keywords' => ['questions','reason','fairness'],
    'weight' => 0.5,
  ],

  'never_split' => [
    'title' => 'Never Split the Difference',
    'summary' => 'Tactical empathy; label feelings; calibrated how/what questions; mirror key phrases; aim for “that’s right”.',
    'keywords' => ['empathy','labeling','mirroring','calibrated questions'],
    'weight' => 0.75,
  ],
  'blue_ocean' => [
    'title' => 'Blue Ocean Strategy',
    'summary' => 'Avoid crowded fights; reframe the problem; eliminate, reduce, raise, create; seek non-obvious value curves.',
    'keywords' => ['reframe','value','innovation'],
    'weight' => 0.5,
  ],

  'models' => [
    'title' => 'Models',
    'summary' => 'Lead with honesty; set boundaries; be specific not generic; playful vulnerability without neediness.',
    'keywords' => ['honesty','boundaries','vulnerability','specific'],
    'weight' => 0.75,
  ],
  'game' => [
    'title' => 'The Game',
    'summary' => 'Use playful teasing sparingly; reward investment; control pacing; avoid over-chasing; light intrigue.',
    'keywords' => ['teasing','pacing','intrigue','playful'],
    'weight' => 0.45,
  ],

  'atomic_habits' => [
    'title' => 'Atomic Habits',
    'summary' => 'Tiny consistent steps; make it obvious, attractive, easy, satisfying; habit stacking; track small wins.',
    'keywords' => ['habits','consistency','stacking','tracking'],
    'weight' => 0.55,
  ],
  'deep_work' => [
    'title' => 'Deep Work',
    'summary' => 'Reduce noise; concentrate on one thing; clear boundaries; brevity; avoid performative busyness.',
    'keywords' => ['focus','clarity','boundaries'],
    'weight' => 0.55,
  ],
];
