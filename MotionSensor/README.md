# Motion Sensor

The device module turns motion and presence variables into one room-level occupancy state. Install one instance per room or sensor. Each instance has its own inputs, off-delay, conditions, output profiles, and manual override.

## Features

- A primary motion variable and any number of additional variables, each configured as a presence state or a pulse, with optional inversion.
- Presence remains active while at least one presence input is active. Pulses restart the configured off-delay.
- An optional block variable pauses both automatic on and off commands while active.
- An optional brightness input gates automatic turn-on below or above a configured threshold.
- Output profiles can select several action variables and typed on/off values. Profile conditions support equality, inequality, active, and inactive checks. The first matching profile wins; put an unconditional default profile last. A profile change switches off outputs left on by the previous profile. If no profile matches, the simple output target is used.
- Manual on/off actions pause automatic control until the user restores automatic mode.
- The instance shows motion, occupancy, remaining off-delay, block and brightness state, active profile, and requested output state in variables and its visualization tile.

## Configuration

Automation is disabled by default for safety. While disabled, motion and condition status remain visible, but output actions and manual controls are suppressed. Enable automation in the instance configuration only after reviewing its selected inputs and outputs.

Select the motion variable, input behavior, and off-delay in the instance properties. Add other motion variables in the additional-input list. Configure a block or brightness input only when the room workflow needs one.

To use a single output, select an actionable variable under **Optional output action variable** and enter its on/off values. To use day/night/evening scenes or control several variables together, create profile output rows. Each row names a profile, selects an actionable variable, and defines its on/off values. Add one or more conditions per profile; all conditions for that profile must match. The first matching profile in output-list order is selected. A profile with no conditions always matches, so keep a default profile last. The simple output target is the fallback when no profile matches.

Leave an output profile's on or off value empty to skip that action for the corresponding transition. This supports momentary scene actions that have distinct calls for occupied and clear states.

Each profile output and the simple output must be an actionable Symcon variable. The module uses `RequestAction` and does not write to another instance's variables directly. Conditions and outputs must use different variables.

## Status and control

The instance variables provide machine-readable state. The visualization tile provides current occupancy, delay, brightness and profile information, plus controls for manual on/off and resuming automatic mode. Manual override persists through instance restarts until automatic mode is explicitly restored.

## Compatibility

IP-Symcon 8.1 or later. The module has no parent data-flow requirement. Existing scripts and flow plans can remain in place during per-room validation; deactivate or remove them only during a separately planned cutover.

## Exported functions

- `MSM_Refresh($instanceID)` refreshes inputs and automatic state.
- `MSM_Tick($instanceID)` advances the off-delay timer.
