<template>
	<div v-if="decoded.show" class="nc-litter-panel" data-testid="error-decoder">
		<NcNoteCard :type="decoded.severity" :heading="heading">
			<p data-field="decoded-detail">{{ decoded.detail }}</p>
			<p v-if="decoded.action" data-field="decoded-action">
				<strong>Next step:</strong> {{ decoded.action }}
			</p>
			<NcButton v-if="conflict" type="secondary" @click="$emit('open-drawer')">
				Open connection help
			</NcButton>
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/**
 * UI-3: plain-English error / notReady panel. The catalog lookup happens
 * server-side (`ErrorDecoderService` over `knowledge/error_codes.yaml`) so the
 * notification, the Activity entry and this panel all quote identical copy.
 */
export default {
	name: 'ErrorDecoderPanel',

	components: { NcButton, NcNoteCard },

	props: {
		/** Output of `decoratedError(state)`. */
		decoded: {
			type: Object,
			required: true,
		},
		conflict: {
			type: [Boolean, String],
			default: false,
		},
	},

	computed: {
		heading() {
			return this.decoded.code
				? `${this.decoded.title} (code ${this.decoded.code})`
				: this.decoded.title
		},
	},
}
</script>
