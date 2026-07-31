<template>
	<div v-if="decoded.show" class="nc-litter-panel" data-testid="error-decoder">
		<NcNoteCard :type="decoded.severity" :heading="heading">
			<p data-field="decoded-detail">{{ decoded.detail }}</p>
			<p v-if="decoded.action" data-field="decoded-action">
				<strong>Next step:</strong> {{ decoded.action }}
			</p>
			<NcButton v-if="offline" type="secondary" @click="$emit('open-drawer')">
				Open connection help
			</NcButton>
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'

/**
 * UI-3: plain-English condition panel. The catalog lookup happens server-side
 * (`ErrorDecoderService` over `knowledge/error_codes.json`) so the notification,
 * the Activity entry and this panel all quote identical copy.
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
		/** True when the Whisker cloud is unreachable, so offer the health drawer. */
		offline: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		heading() {
			// LR4 condition codes are short strings ("DFS", "BR"), not numbers.
			// `decoratedError()` already drops anything numeric, because appending the
			// raw error register turned every fault into "Something needs a look (1)"
			// — a number the operator cannot look up anywhere.
			const code = this.decoded.code
			const suffix = typeof code === 'string' && code.trim() !== '' ? ` (${code.trim()})` : ''
			return `${this.decoded.title}${suffix}`
		},
	},
}
</script>
