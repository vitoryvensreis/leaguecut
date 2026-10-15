<?php
declare(strict_types=1);
// Instagram Graph synchronization is suspended while the Meta app awaits approval.
// All Instagram view observations currently come from the budget-limited Apify job.
// Keep this CLI placeholder to prevent old automation from silently contacting Meta.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
fwrite(STDERR,"Instagram official sync disabled: use Apify daily 02:00 Fortaleza.\n");
exit(2);
