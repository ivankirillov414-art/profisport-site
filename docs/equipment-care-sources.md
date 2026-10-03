# Equipment passport maintenance references

Verified 2026-10-03. A manufacturer's inspection interval is not a component's expiry date. No generic mileage is assigned to unknown components.

| Source | Application in the passport |
| --- | --- |
| [SRAM chain manual](https://docs.sram.com/en-US/publications/6sfLCOGTn6FE98W8vXLqm0/UM%20-%20Chains) | 0.8% elongation criterion and optional owner measurement only when the recorded chain is SRAM. Requires a compatible wear gauge. |
| [Shimano hydraulic brake user manual](https://si.shimano.com/en/pdfs/um/8KZ0A/UM-8KZ0A-005-ENG.pdf) | 0.5 mm pad material criterion explicitly limited to disc pads covered by the manual; not assigned as a generic baseline. |
| [Schwalbe tire wear FAQ](https://www.schwalbe.com/en/technology-faq/tire-wear/) | 2,000–5,000 km is a reference range for standard Schwalbe tires, displayed only for recorded Schwalbe tires; not a precise remaining-life counter. |
| [RockShox suspension manual](https://docs.sram.com/en-US/publications/5ODr3E6BhL1uWDnWhq4ATB/UM%20-%20Suspension?models=fs-lyrk-ult-e1) | Explains common 50/200 riding-hour service intervals; the passport requires the model's own service schedule and does not apply this interval to all forks. |
| [Rollerblade technical manual](https://www.rollerblade.com/assets/pdf/manual.pdf) | Wheel rotation, wear-led replacement, bearing care, brake wear marks and checks. No universal wheel lifespan. |
| [Bones bearing maintenance](https://bonesbearings.com/support/maintenance) | Skateboard bearing cleaning and lubrication according to condition. Other skateboard checks are labelled workshop guidance. |
| [Rossignol care instructions](https://www.rossignol.com/sk-en/care-instructions.html) | Seasonal inspection and a maximum of 30 skiing days between professional binding checks for Rossignol alpine skis. Counter begins with a logged service, not an assumed purchase-time inspection. |
| [Fischer guides and manuals](https://www.fischersports.com/help-support/guides-manuals/) | Nordic ski and skin-specific care. Alpine binding criteria are not applied to Nordic skis. |

The model photograph comes from the purchased product record. A missing image is labelled explicitly. Component navigation is separate from the photo because generic hotspot coordinates do not describe the geometry of a purchased model.

Owners can record cumulative usage and inspection, service and replacement events. The API verifies ownership and CSRF, rejects decreasing counters and records abnormal replacements without training the personal prediction. Two completed normal replacement cycles are required before a median-based personal lifetime is shown. New physical replacements reset cycles; an inspection or purchase alone does not. Existing confirmed component replacement and compatible-purchase flows remain in place.
