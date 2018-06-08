# Summary

Exponential back-offs prevent overloading an unavailable service by doubling the timeout each iteration. This class uses
an exponential back-off algorithm to calculate the timeout for the next request.

# Sample Usage

Please check and run scripts under folder _examples/_.

* _01_return_empty_value_when_failed.php_: to return a non-empty value back after 3 failed attempts, where each failed attempt returns an empty value back.
* _02_throw_an_exception_when_failed.php_: to return a non-empty value back after 3 failed attempts, where each failed attempt throws an exception out.
* _03_use_customized_handler_when_failed.php_: to return a non-empty value back after 3 failed attempts, where each failed attempt returns FALSE while calling
the customized function.
* _04_more_options.php_: to show how to define customized options when running exponential backoff.
