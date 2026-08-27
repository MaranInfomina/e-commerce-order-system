<?php

// Intentionally empty. All HTTP routing is either the JSON API in
// routes/api.php or the Nuxt frontend, which nginx proxies to directly and
// which never reaches Laravel. This file must still exist — bootstrap/app.php
// registers it with ->withRouting(web: ...) regardless of content.
