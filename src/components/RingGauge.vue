<template>
	<svg
		:class="['nc-litter-ring', `is-${tone}`]"
		viewBox="0 0 40 40"
		role="img"
		:aria-label="ariaLabel">
		<circle class="nc-litter-ring__track" cx="20" cy="20" r="16" />
		<circle
			class="nc-litter-ring__value"
			cx="20"
			cy="20"
			r="16"
			:stroke-dasharray="circumference"
			:stroke-dashoffset="offset"
			transform="rotate(-90 20 20)" />
		<text
			class="nc-litter-ring__pct"
			x="20"
			y="20"
			dominant-baseline="central"
			text-anchor="middle">{{ centreLabel }}</text>
	</svg>
</template>

<script>
import { ringCircumference, ringOffset } from '../utils/format.js'

/** Ring geometry: r=16 in a 40x40 viewBox. */
const RADIUS = 16

/**
 * The app's one ring-gauge primitive: a track, a value arc and a percentage in
 * the middle. Reused for the waste-drawer gauge (fills toward full) and the
 * litter gauge (drains toward empty); the caller decides the tone so the same
 * primitive can grade in either direction.
 */
export default {
	name: 'RingGauge',

	props: {
		/** 0..100; null/undefined draws an empty ring and an em dash. */
		pct: {
			type: Number,
			default: null,
		},
		/** Severity token: ok | warn | danger | run | evac | sleep | idle. */
		tone: {
			type: String,
			default: 'ok',
		},
		/** Screen-reader description, e.g. "Waste drawer 62% full". */
		ariaLabel: {
			type: String,
			default: 'Level gauge',
		},
	},

	computed: {
		hasValue() {
			return this.pct !== null && this.pct !== undefined && Number.isFinite(Number(this.pct))
		},
		circumference() {
			return ringCircumference(RADIUS).toFixed(2)
		},
		offset() {
			return ringOffset(this.hasValue ? Number(this.pct) : 0, RADIUS).toFixed(2)
		},
		centreLabel() {
			return this.hasValue ? String(Math.round(Number(this.pct))) : '—'
		},
	},
}
</script>
