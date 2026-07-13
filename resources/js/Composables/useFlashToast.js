import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Module-level refs = a tiny singleton store, shared by every component
// that calls useFlashToast() — same pattern as useAppShell.js. This
// matters here specifically because AppLayout is the only place that
// actually renders <Toast :toast="toast" />; any other component (e.g.
// a page calling show() manually for instant client-side feedback, like
// Master Grid's drag-and-drop) needs its show() to update that SAME
// ref, not a private one nobody is displaying.
const toast = ref(null)
let timer = null

/**
 * Shared toast state, driven either by the server (session flash data
 * relayed through Inertia) or manually via show() for instant
 * client-side feedback (e.g. a pre-flight check before a request is even
 * sent).
 *
 * REQUIRES: HandleInertiaRequests must share a 'flash' prop, e.g.
 *
 *   'flash' => [
 *       'success' => fn () => $request->session()->get('success'),
 *       'warning' => fn () => $request->session()->get('warning'),
 *       'error'   => fn () => $request->session()->get('error'),
 *       'deleted' => fn () => $request->session()->get('deleted'),
 *   ],
 *
 * Add that to app/Http/Middleware/HandleInertiaRequests.php's share()
 * method if it isn't there yet — otherwise controller flash() calls will
 * silently never reach the browser.
 */
export function useFlashToast() {
    const page = usePage()

    function show(message, type = 'success') {
        if (! message) {
            return
        }

        toast.value = { message, type }

        clearTimeout(timer)
        timer = setTimeout(() => {
            toast.value = null
        }, 4500)
    }

    watch(
        () => page.props.flash,
        (flash) => {
            if (! flash) {
                return
            }

            if (flash.success) show(flash.success, 'success')
            else if (flash.warning) show(flash.warning, 'warning')
            else if (flash.error) show(flash.error, 'error')
            else if (flash.deleted) show(flash.deleted, 'deleted')
        },
        { immediate: true, deep: true }
    )

    // Inertia shares validation failures via the `errors` prop
    // automatically (no controller flash() call involved) — a 422
    // redirect-back with $errors otherwise produces no toast at all,
    // even though the request genuinely failed. This surfaces that as
    // a red ❌ toast the same way any other 'error'-type flash does.
    // Only fires on a NEW set of errors (an object that wasn't empty
    // before, or has different keys) so it doesn't re-toast on every
    // unrelated prop update while the errors are still on-screen.
    let lastErrorKeys = ''

    watch(
        () => page.props.errors,
        (errors) => {
            const keys = errors ? Object.keys(errors).sort().join(',') : ''

            if (keys && keys !== lastErrorKeys) {
                show('Please check the form for errors.', 'error')
            }

            lastErrorKeys = keys
        },
        { immediate: true, deep: true }
    )

    return { toast, show }
}