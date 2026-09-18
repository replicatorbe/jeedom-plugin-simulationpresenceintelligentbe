# Changelog

## 1.0

First release.

- Simulation groups: a group holds lamps and sockets, a start condition and a
  time window.
- Learning from the Jeedom history of each lamp's state: the plugin works out
  when it goes on and off, weekday by weekday, and replays that day with enough
  randomness never to repeat itself. The time is learned inside the quarter-hour
  slot: a lamp switched on at 19:00 every evening comes back around 19:00.
- Learned habits follow the sun: the plan is shifted by the gap between the
  average sunset of the learned days and the sunset of the day being replayed,
  switch-ons before noon on the sunrise and those after noon on the sunset, the
  duration of a switch-on preserved and the shift capped at four hours. Without
  it, an evening learned in mid-September, when the sun sets at 19:51, would
  light the façade three hours after nightfall at the solstice, when it sets at
  16:41.
- Days without a single change count towards learning: Jeedom only writes a
  history row when the value changes, and a lamp switched on six evenings out of
  twenty-eight has history for those six days only.
- Two exceptions to that: days earlier than the start of the lamp's history — a
  command historized yesterday has nothing to say about the twenty-seven days
  before — and, with "Ignore empty days", those on which no lamp in the group
  moved, which are empty houses. A day on which this lamp was not used while
  others were moving remains a genuine observation, and it is kept; the number
  of days left out is shown in the learning table.
- A lamp that is always on — a night light, a corridor — is replayed as such:
  switched on when the time window opens, switched off when it closes.
- "Historize the lamps" button: historization of the chosen lamps' state is
  turned on in one click, without which there would be nothing to replay.
- Invented evenings for lamps with no past, built around the sunset of your
  position, with a chance of taking part, a random drift and a bedtime — a
  bedtime after midnight included, which makes the evening run until the window
  closes.
- Variability shifts the whole day: one draw for the entire group, and a second
  one per lamp on top. The lamps of one group therefore shift together — on an
  evening when you come home late, the whole house lights up late — and no
  evening is ever cancelled, whatever the setting.
- A plan drawn once a day and reproducible: a box restarted mid-evening picks
  the same one up, it does not start another on top. The "Draw another plan"
  button really does draw another one.
- The days on which the plugin drove the lamps are excluded from learning, and
  marked on every day of simulation rather than at start-up alone: it never
  learns its own inventions, not even those of a holiday simulation started once
  for a fortnight.
- Start condition: a list of information commands with a test and an expected
  value, combined with AND or OR, with a confirmation delay and a release delay.
- The bounds of the time window take a solar time as readily as a fixed one:
  "sunset-30", "sunrise+15", typed in English or in French and stored in
  English, the offset capped at twelve hours. "Nothing before 07:00" forbade
  two hours of broad daylight in June, when the sun rises at 05:31; the time
  the bound gives today is shown next to the field.
- Safeguards: time window, maximum number of lamps on at the same time, minimum
  and maximum switch-on durations held to the minute, restore of the initial
  state when stopping — even for a lamp removed from the group in the meantime —
  and respect for a manual action. A numeric field left empty falls back on its
  default value.
- Lamp selector: walks through the installation, groups by room, tells lamps
  from sockets and from devices matched by name, and switches a lamp on so you
  can recognise it. It only offers what it would also know how to switch off;
  the rest goes through "All devices", where you name the commands yourself.
- Failures show: a failed order is published in Jeedom's message centre, the
  lamp is left aside for a quarter of an hour instead of being retried every
  minute, and a lamp deleted from the installation is flagged in the tables.
- A table of what the plugin has learned, lamp by lamp, and a preview of a day —
  today, tomorrow, the day after — computed by the code that will really play
  the plan. The preview draws every lamp as a twenty-four-hour bar: periods on,
  the allowed window in the background, a graduation every six hours, a mark at
  sunset and, for today, a mark at the current time. The list of times stays
  under the bar.
- "Rehearse the evening in two minutes" button: the day's plan played for real
  on the lamps, compressed into two minutes, with a progress bar and a log of
  the changes. The range spread out is the one where something happens, not the
  whole window; the lamps are put back the way they were at the end; the run is
  driven by the browser, one request per change, so closing the page stops
  everything. The button refuses to start while the simulation is running.
- Commands: simulation, start, stop, return to the condition, plan source, next
  change, lamps on, draw another plan.
- Health page: position set, lamps tracked, lamps without history. The missing
  position message clears itself as soon as the coordinates are set.
- No daemon, no dependency, no network call.
