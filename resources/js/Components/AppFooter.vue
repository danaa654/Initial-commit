<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const activeAcademicTerm = computed(() => page.props.activeAcademicTerm)

const currentYear = new Date().getFullYear()
</script>

