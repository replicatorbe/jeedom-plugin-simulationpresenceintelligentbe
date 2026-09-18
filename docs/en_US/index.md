# Simulation de présence intelligente

Make the house look lived in by replaying what it usually does.

The plugin keeps its French name throughout Jeedom: the core displays the name a
plugin declares, as it is, and never translates it. Whatever language your
Jeedom speaks, look for **Simulation de présence intelligente** in the menus.

The plugin drives no hardware of its own: it commands the lamps and sockets your
other plugins have created — Zigbee, Z-Wave, Hue, MQTT, Wi-Fi sockets, older
modules. It reads their history, learns when each one goes on and off depending
on the day of the week, and replays that day with enough randomness never to
repeat itself.

There is no daemon to watch and no dependency to install: everything is PHP, in
the core's cron.

## Before you start

Set the position of your installation in **Settings → System → Configuration →
General**. Without a latitude and a longitude, invented evenings fall back on a
fixed sunset at 7 pm all year round: in June the lamps switch on in broad
daylight, and nothing gives a simulation away faster from the street.

It is the same position Jeedom already uses for `#sunset#` in scenarios. The
plugin's configuration page shows it, together with today's sunrise and sunset,
so that a wrong position shows up at once.

As long as it is missing, the plugin posts a message in Jeedom's message centre.
It clears itself as soon as the coordinates are set, with nothing to
acknowledge: a warning you cannot make go away by fixing what it complains about
is a red counter for life.

## Creating a group

A **group** holds the lamps of one façade or one floor, together with the
condition that starts the simulation and the time window that bounds it.

1. **Plugins → Security → Simulation de présence intelligente → Add a group.**
   Name it after what it makes believe: "Façade", "Upstairs", or simply "House".
2. **Lamps tab.** Pick the lamps and sockets in the selector.
3. **Learning tab.** Click **Historize the lamps**. That is the first thing to
   do, and the only urgent one.
4. **Start tab.** Tell the plugin when to start on its own, or leave the
   condition unticked and drive it with **Start**.
5. **Save.**

## Historize the lamps first

The plugin learns by reading the **Jeedom history** of each lamp's state
command. Without history there is nothing to replay — and Jeedom's history only
begins the moment you tick the box. The **Historize the lamps** button, in the
*Learning* tab, turns historization on for the state of every chosen lamp, in
one go.

So it takes a few days before the plugin replays your habits instead of
inventing: three observed days by default. Until then it does not stay silent,
it invents — but what it plays on the first evening owes nothing to your house.

The **What the plugin has learned** table gives, lamp by lamp, the number of
observed days, the average time spent on, and what it will do tonight: replay or
invent. It is the only page that explains why two lamps of the same group are
not treated the same way.

Jeedom only writes a history row when the value changes: a guest-room lamp
switched on six evenings out of twenty-eight has history for those six days
only. The other twenty-two count just as much, and the plugin counts them —
without them it would average over the active days alone, and would switch that
lamp on almost every evening while claiming to replay it faithfully. Two
exceptions, described further down: days earlier than the start of the history,
and days on which the whole group stayed still.

A lamp that publishes no state can never be learned, only invented. The
**Health** page counts them.

## The lamp selector

The selector walks through your installation and shows only what can switch on
and off, grouped by room: first what carries the core's *Light* generic types,
then switched sockets, then devices recognised by their "On" and "Off" commands
— many older protocols leave the generic types empty, and without that safety
net their lamps would be unreachable.

You tick **devices**, not commands: the plugin keeps track of which command
switches on, which switches off and which publishes the state. Every line
switches the lamp on and off for real, which is still the fastest way to find
out which of your three lamps is called "Module 3".

A device is only offered if there is something to switch it on **and** something
to switch it off; a toggle counts for both. A lamp you can switch on without
being able to switch it off burns until morning, which is precisely the
signature a presence simulation sets out to hide. Those devices remain reachable
through **All devices**, where you name the command that switches on and the one
that switches off yourself; if either is still missing when you confirm, the
plugin tells you which ones it left out rather than letting them vanish without
a word.

What is ticked is driven **during the simulation, and only during the
simulation**: outside it, the plugin touches nothing.

## What the plugin learns

The model rests on four ideas, and it is what makes the difference between a
credible simulation and a timer.

**The day is cut into quarter-hour slots.** Nobody switches the living room on
at 19:07 every week, but many people switch it on "around a quarter past seven".
Finer would learn nothing more, wider would lose the difference between dinner
and bedtime.

The slot decides **whether** the lamp comes on; the time itself is learned
inside the slot. The plugin keeps where, within the quarter of an hour, the
change fell, and replays around there, give or take a few minutes. A lamp
switched on at 19:00 every evening therefore comes back around 19:00, and not at
19:07 as a uniform draw inside the slot would have it — a small shift, but a
systematic one, and it shows on a house watched several evenings running.

**It does not learn the probability of being on, but those of switching on and
switching off.** That is the difference between a credible plan and a flicker:
replaying an occupancy probability slot by slot, independently, would give a
lamp that goes on and off ten times in an evening. By reasoning on the changes,
the plan inherits the observed durations naturally.

**With few days, the observed frequency is corrected.** Over three weeks a slot
holds only three samples: a raw frequency there is 0 or 1, never anything else.
Each frequency is therefore blended with an estimate drawn from the general
shape of the day, the more strongly the rarer the observations. That is what
makes the plugin useful from the first week without caricaturing the little it
has seen.

**One profile per weekday, falling back on all days.** A Tuesday looks far more
like a Wednesday than like a Saturday, but it takes several weeks to know it.
As long as the weekday has not been observed often enough, the plan is drawn
from all days together.

Profiles are rebuilt every night, once per lamp: it is the plugin's only costly
operation, and it never falls during the evening.

A lamp that is always on — a night light, a corridor — is replayed too: seen on
at midnight every day, never seen switching on, it is switched on when the time
window opens and off when it closes, rather than left dark all evening. The
house would otherwise lose the very lamp that never goes out.

**Settings, *Learning* tab.**

- **Look back over** — the depth of history read, 28 days by default. Four
  weeks: enough for every weekday to have been seen four times, not enough for a
  change of season to go unnoticed.
- **Replay from** — the number of observed days below which the plugin invents
  rather than replays. Three by default.
- **Variability** — how far the whole day is shifted, 30 % by default. The
  plugin draws one shift for the entire group — half an hour at most — and a
  second one per lamp on top — a quarter of an hour at most — both in proportion
  to the setting. At 0 every evening looks alike; at 100 they move by up to
  three quarters of an hour either way. No evening is ever cancelled, whatever
  the setting: variability shifts, it does not remove.
- **Follow the sun** — ticked by default. Learned habits are repositioned
  against the sunset and the sunrise of the day being replayed, instead of
  being replayed by the clock. That is the next section, and it is the box not
  to untick.
- **Ignore empty days** — ticked by default. A day on which no lamp in the
  group moved is taken for an empty house, and does not enter the learning.

**The lamps of one group shift together.** That is what the group's share of the
draw does: on an evening when you come home late, the whole house lights up
late, rather than six lamps each deciding on their own. It is the only
correlation between lamps the model produces, and it is the one most sorely
missed by whoever watches a façade several evenings running — the order in which
the rooms light up must have a visible cause. The per-lamp share, smaller, keeps
the group from moving as a single block.

## Habits follow the sun

A habit is learned in one season and replayed in another. In Nivelles the sun
sets at 19:51 in mid-September and at 16:41 at the solstice: three hours and
ten minutes apart on the clock, five hours eighteen between June and December,
plus the one-hour jump when the clocks change. An evening learned in autumn and
replayed as it stands in December would therefore light the façade three hours
after nightfall — just as the rest of the street goes dark, which is noticed
far more than a dark house.

**Follow the sun**, in the *Learning* tab, repositions habits on the sun of the
day being replayed. The profile keeps the average sunset of the days that built
it; the day's plan is shifted by the gap between that average sunset and
today's. Switch-ons before noon follow the sunrise, those after noon the
sunset: morning and evening do not move the same way as the days shorten.

The **duration** of a switch-on is preserved: a switch-on is moved whole, with
its switch-off, and not bound by bound — otherwise an evening would last an
hour longer in December than in June without anyone asking for it. The shift is
capped at four hours: beyond that it is no longer a season that has changed, it
is a setting that has gone astray.

The repositioning happens before the time window, never after: a window applied
to the times of another season would cut in the wrong place.

Untick the box if you would rather have the learned times replayed by the
clock, exactly as they were observed.

## The days that teach nothing

**Ignore empty days** — a day on which **no** lamp in the group moved is an
empty house: nobody switched anything on, nobody switched anything off, and an
absence has nothing to teach a presence simulation. It is left out, and for
every lamp of the group at once.

The distinction is worth reading: a day on which *this* lamp was not used while
others were moving remains a genuine observation, and it is kept. A guest room
dark on a Tuesday when the living room came on says something about the house.
It is the stillness of the **whole** group, and that alone, that gives the
absence away.

Without that sorting, every week of holiday makes the simulation a little more
timid: empty days pull the switch-on rates down, and the effect adds up — the
more you are away, the less the simulated house lights up, which is the exact
opposite of what is asked of it. The number of days left out is shown at the
top of the table in the *Learning* tab.

Days **earlier than the start of the lamp's history** do not count either,
whatever the box says. A command historized yesterday has nothing to say about
the twenty-seven days before: counting them as so many "never on" days would be
an invented observation, and it would pull the profile down just as much as the
days away.

## When it has not learned yet

A lamp with no past gets an **invented evening**, built around sunset — the only
thing one really knows about the evening of a house one does not know.

- **One evening in** — the probability that a given lamp takes part in the
  evening, 60 % by default. Less than 100 % keeps six lamps from switching on
  every night on the same minute, which is the flaw that gives a simulation
  away.
- **Switch on at sunset** — so many minutes before or after, −15 by default.
- **Off around** — bedtime, 23:00 by default. A time entered after midnight —
  "00:30" — makes the evening run until the time window closes, which is what
  you meant.
- **Drift** — the random spread applied on either side of each time, 25 minutes
  by default.
- **One morning in** and **Up around** — the morning counterpart, off by
  default: an empty house whose lamps come on every morning at six is as
  implausible as a house dark every evening.

## A plan that does not change mid-evening

The day's plan is drawn at random, but the draw is **reproducible**: the seed is
made of the group's identifier, the lamp's, the date, and a salt kept in the
group's configuration. A box restarted at
9 pm therefore picks the same evening up where it left it; it does not start
another one on top. The next day the plan differs; the same evening, it differs
from one lamp to the next.

Every minute, the plugin does not replay missed changes one by one: it reads the
state the plan expects at that minute and enforces it. A box down for two hours
resumes the evening in the state it should be in, without a burst of switch-ons
to catch up.

**The days on which the plugin itself drove the lamps are excluded from
learning.** Without that, it would learn its own inventions and drift a little
further every week. Every day of simulation is marked on the day itself, not at
start-up alone: a holiday simulation starts once for a fortnight, and the other
thirteen days have to be excluded as well. The record is kept for ninety days,
and longer if you ask the plugin to look further back — otherwise, past three
months, it would relearn the days it invented itself.

The **Draw another plan** button, in the *Learning* tab, moves that salt on: the
day's plan is forgotten, and the one that replaces it is another draw, not the
same one rebuilt identically. Press it as often as you need to land on an
evening that suits you; between two presses the plan no longer moves, including
after a reboot of the box.

## Starting: the condition

*Start* tab. The condition describes the state the house must be in for the
simulation to start on its own: the alarm is armed, "Away" mode is active,
nobody is detected any more.

It is a **list** of information commands, each with a test (`==`, `!=`, `>`,
`>=`, `<`, `<=`) and an expected value, not a single condition: "the alarm is
armed" and "nobody is home" are two distinct pieces of information in almost
every installation, and requiring them together is exactly what keeps the lamps
from coming on while someone is asleep upstairs. The **Require** field decides
whether every line must be true (AND) or just one (OR). The **Evaluate now**
button shows each command's current value and the result.

**Confirm for** — how long the condition must hold before starting, 2 minutes by
default. It keeps a sensor that hesitates for a minute from launching a whole
evening.

**Then stop after** — the release delay, once the condition has become false
again. Zero to stop within the minute.

A condition whose command has been deleted is held to be false: better a
simulation that does not start than one that starts while the house is occupied.

**Start** and **Stop** override the condition, and keep overriding it until
**Return to the condition** is played: someone stopping the simulation from
their phone does not want it back the next minute because the alarm is still
armed.

## The time window and the safeguards

**Nothing before** / **Nothing after** — the allowed window, 07:00 to 23:30 by
default. Each bound takes a fixed time — `07:00` — **or** a solar time:
`sunset-30`, `sunrise+15`. A fixed bound makes little sense for a setting meant
to protect the night: "nothing before 07:00" forbids two hours of broad
daylight in June, when the sun rises at 05:31, and lets an hour of pitch dark
through in December. `sunrise+15` holds both seasons with nothing to come back
to.

Both English and French are accepted — `sunset-30` or `coucher-30`,
`sunrise+15` or `lever+15` — and the bound is stored in English, like the
core's tags (`#sunset#`): what is saved does not depend on the language of
whoever typed it. The offset is capped at twelve hours. The time the bound
gives today is shown next to the field, because `sunset-30` means nothing until
you have seen it come out at 21:21.

The window shifts nothing: a switch-on planned outside the window is
**dropped**, never postponed, because pushing everything to the opening minute
would be noticed from the street far more than a lamp that does not come on. A
lamp still on at closing time, on the other hand, is switched off — that is the
promise of the setting.

**Switch-on durations are held to the minute.** A switch-on lasts at least
twelve minutes and at most seven hours. One that could not hold its twelve
minutes because the window is closing is dropped rather than played as a flash:
a façade that lights up for three minutes is noticed far more than one that
stays dark. The ceiling, for its part, does not apply to a lamp observed on at
that hour almost every day: cutting it after seven hours would invent a
switch-off nobody ever made, and a corridor night light would blink once a
night.

**Lamps on at most** — how many lamps may be on at the same time, 3 by default,
0 for no limit. Lamps already on keep the floor: switching off the one that has
been burning for an hour to light the next would produce a parade of rooms no
house ever performs.

**Restore the state when stopping** — every lamp goes back to the state it had
at start-up, recorded before the first order. Without it, coming home at
midnight means switching off three lamps you never switched on, every night of
the holiday. The record holds the whole lamp — its name and its commands, not
just its state — which is what allows even a lamp removed from the group since
start-up to be put back: otherwise it would stay on all night, and you would
look a long time for the reason.

**After a manual action** — if someone switches a lamp on or off by hand during
the simulation, the plugin leaves it alone for that many minutes, 60 by default.
The action is spotted from the gap between what the plugin ordered and what the
lamp publishes, once the response time set in the plugin configuration has
passed. A lamp touched by hand is not restored on stop either: it belongs to
whoever switched it.

A numeric field left empty falls back on its default value, the one the field
shows in grey, and not on zero: "I do not know what to put" is not "no
safeguard".

## When a lamp does not answer

An order that fails — the device has been disabled, the command has gone, the
module no longer answers — leaves a line in the log **and** a message in
Jeedom's message centre. A presence simulation that degrades without saying so
is exactly what a security plugin must not do, and the log does not read itself.

The lamp is then left aside for a quarter of an hour before being tried again.
Without that delay, a disabled device would produce an error and a fresh
attempt every minute, all evening long. A group's messages are cleared at its
next start: they describe the evening under way, not the previous ones.

A lamp **deleted** from the installation is flagged in the lamp table and in the
one on the *Learning* tab. It is the only failure the plugin cannot work around:
open the selector again and choose the lamp anew.

## The commands created

| Command | Type | Role |
|---|---|---|
| **Simulation** (`state`) | binary info | 1 while the simulation is running. Historized, and tied to both buttons: the tile toggles. |
| **Start** (`on`) | action | Starts at once and overrides the condition. |
| **Stop** (`off`) | action | Stops, restores the lamps, and overrides the condition. |
| **Return to the condition** (`auto`) | action | Drops the manual override: the condition takes over again. Created hidden. |
| **Plan source** (`mode`) | info | "history", "invented", or "2 learned, 3 invented". |
| **Next change** (`next`) | info | "20:42 switch on Living room". The only way to check at a glance that the plugin means to do something tonight. |
| **Lamps on** (`lit`) | numeric info | How many lamps the simulation is keeping on. Historized. |
| **Draw another plan** (`replan`) | action | Another draw for the day, not the same plan rebuilt. Created hidden. |

The two hidden commands can be shown again if you have a use for them: they are
mostly meant to be played from a scenario.

## Previewing a day

*Learning* tab, **Preview of a day**: today, tomorrow or the day after, lamp by
lamp, with the times and the source of the plan. It is computed by the server,
with the very code that will play the plan — two implementations, one for the
preview and one for execution, would diverge on the first setting added, and the
preview would lie without anyone knowing.

Every lamp gets a **twenty-four-hour bar** showing its periods on, with the
allowed window in the background, a graduation every six hours, a mark at
sunset and, for today, a mark at the current time. The list of times stays
under the bar, with the time spent on and the number of switch-ons.

A list is read line by line; a bar is read at a glance, and shows what no list
shows: six lamps coming on at the same minute, or a two-hour hole in the middle
of the evening.

Looking at tomorrow does not change tonight: previewing another day leaves the
day's plan untouched.

## Rehearsing the evening in two minutes

The **Rehearse the evening in two minutes** button, next to the preview, plays
the day's plan **for real** on your lamps, compressed into two minutes. They
really do switch on and off: it is the only way to check that they all answer,
and in the planned order, without waiting for the evening. Every lamp's state
is recorded before starting and put back at the end — a rehearsal must leave no
trace behind it.

What is spread over those two minutes is the range where something happens:
from the first to the last change of the plan, with a margin, and not the whole
time window. A window opening at 07:00 whose first switch-on falls at 19:26
would otherwise leave you waiting a hundred seconds in front of dark lamps, out
of the hundred and twenty the rehearsal lasts.

The run is driven by the browser, one request per change: **closing the page
stops everything**, and so does switching to another group. A single request
sleeping for two minutes would end in a timeout, and closing the tab has to be
enough to interrupt whatever is commanding your lamps.

A progress bar and the log of the changes are shown during the rehearsal.
**Stop the rehearsal** interrupts it and puts the lamps back the way they were,
without waiting for the end.

The button refuses to start while the simulation is running: two plans played
at the same time on the same lamps would contradict each other. It also refuses
when there is nothing to play today, rather than keeping you waiting two
minutes in front of a dark façade.

## The plugin configuration

**Plugins → Plugin management → Simulation de présence intelligente →
Configuration.**

- **Lamp response time** — the seconds a lamp is given to confirm an order, 120
  by default. After that, a gap between the ordered state and the published one
  is taken for a human action. Raise it if your modules are slow to publish
  their state.
- **Write the plan of the day** — every plan drawn is written to the log, lamp
  by lamp, with its times. Verbose, but it is the only way to understand
  afterwards why an evening went the way it did.
- **Position** — a reminder of the installation's position, with today's sunrise
  and sunset.

## Frequently asked questions

**The plugin switched nothing on the first evening.** That is normal and
intended: a lamp with no past only takes part in six evenings out of ten by
default, and the draw may have left it out. The day's preview says so line by
line. Check as well that the simulation is running — the **Simulation** command
is 1 — and that the planned time falls inside the window.

**How long before it really replays my habits?** Three observed days per lamp by
default, and the history only begins the moment you click **Historize the
lamps**. So count on a good week, and a fortnight for Saturday to tell itself
apart from Tuesday.

**Does the plugin learn its own evenings?** No. The days on which it drove the
lamps are excluded from learning; it never relearns what it invented.

**I switched a lamp off by hand during the simulation.** The plugin notices and
leaves it alone for the time set in **After a manual action**. It is not
restored on stop either.

**On some evenings every lamp comes on later.** That is intended, and it is the
group shift of **Variability**: one does not come home at the same time every
evening, and when one comes home late the whole house lights up late. Lower the
variability to tighten the evenings, raise it to spread them out; none of them
is cancelled either way.

**In December it switches on far earlier than in September.** That is **Follow
the sun**: learned habits are repositioned on the day's sunset, which has moved
back by more than three hours in the meantime. It is exactly what the house
does when it is lived in.

**Does the rehearsal really switch the lamps on?** Yes, for real: it plays the
day's plan on your lamps, compressed into two minutes. It is the only way to
check that they answer without waiting for the evening. They are put back the
way they were at the end, and the simulation has to be stopped for the button
to agree to start.

**My lamp does not show up in the selector.** It is most likely missing
something to switch it off: the plugin offers no lamp it would not know how to
switch off. Open **All devices** and name the command that switches on and the
one that switches off yourself.

**Does the simulation resume on its own after a reboot?** Yes. The "running"
state and the full record of the lamps are stored in the database, not in the
cache; the plan itself rebuilds identically thanks to its seed and its salt.

**Can the same lamp be in two groups?** Yes, but both groups will send it their
orders and contradict each other: the last one wins.

**Is there a daemon, a dependency, an account somewhere?** No. Everything runs
in the core's cron, and nothing leaves the box.

**Where is the log?** Analysis → Logs → `simulationpresenceintelligentbe`.
Starts, stops, manual actions and failed orders each leave a line; the full plan
goes there too if you tick **Write the plan of the day**. Failed orders are also
published in the message centre, so that a failure shows without being looked
for.
