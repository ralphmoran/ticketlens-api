import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { buildEchoOptions } from '@/composables/echoConfig';

window.Pusher = Pusher;

const options = buildEchoOptions(import.meta.env, window.location);

// Without a Reverb app key there is no live channel; consumers already guard on window.Echo.
if (options) {
    window.Echo = new Echo(options);
}
