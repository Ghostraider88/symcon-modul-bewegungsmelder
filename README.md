# IP-Symcon Motion Sensor

This library provides a type 3 device module for replacing room-specific motion sensor scripts and flow plans with separately configurable instances.

## Current implementation

The `Motion Sensor` instance supports a primary motion input plus additional inputs, each configured as a presence state or a motion pulse. It exposes motion, occupancy, remaining off delay, automatic blocking, brightness, active output profile, requested output state, and manual override in its variables and visualization tile. An optional block variable can pause automatic output changes, and an optional brightness threshold can limit automatic turn-on. Output profiles can select several action variables and typed on/off values for day, night, evening, or other modes. Each room can use its own instance and settings.

The module does not create other instances or select objects by name. A selected output is addressed only through its configured variable ID and must have an action. The module leaves archive logging to the user.

## Requirements

- IP-Symcon 8.1 or newer
- PHP 8.1 or newer through the IP-Symcon runtime

## Configuration

Select a motion input variable and choose whether it represents a continuously asserted presence state or a pulse. Add more inputs in the list; occupancy remains active while any presence input is active, and any new pulse restarts the delay. Configure per-input inversion, a block variable, an optional numeric brightness variable and threshold, and an off delay in seconds. A simple output action variable can receive typed values for occupied and clear states. For scene behavior, profile rows can control several action variables together, with profile conditions selecting the first matching day/night/evening mode and the simple output as fallback.

Manual output control holds the selected output until automatic mode is explicitly restored. With no output selected, the instance still provides local status and control without changing another object.

## Instance configuration

The generic input, delay, block and brightness conditions, manual override, typed output, and profile output layers are implemented. Each IP-Symcon instance stores its own selected variables and output profiles in Symcon. These instance properties are not part of this repository and are not changed by updating the library.

Review each instance's selected inputs and outputs before enabling automation. Existing scripts and flow plans can remain during validation; deactivate them only as part of a separately planned room cutover.
