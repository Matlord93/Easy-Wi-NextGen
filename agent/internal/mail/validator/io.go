package validator

import "context"

func (e *Engine) lookupTXT(ctx context.Context, name string) ([]string, error) {
	var (
		out []string
		err error
	)
	err = e.withRetry(ctx, func(callCtx context.Context) error {
		out, err = e.resolver.LookupTXT(callCtx, name)
		return err
	})
	return out, err
}

func (e *Engine) lookupMX(ctx context.Context, name string) ([]*MXRecord, error) {
	var (
		out []*MXRecord
		err error
	)
	err = e.withRetry(ctx, func(callCtx context.Context) error {
		out, err = e.resolver.LookupMX(callCtx, name)
		return err
	})
	return out, err
}

func (e *Engine) lookupAddr(ctx context.Context, ip string) ([]string, error) {
	var (
		out []string
		err error
	)
	err = e.withRetry(ctx, func(callCtx context.Context) error {
		out, err = e.resolver.LookupAddr(callCtx, ip)
		return err
	})
	return out, err
}

func (e *Engine) probeTLS(ctx context.Context, host string, port int) (TLSProbeResult, error) {
	var (
		out TLSProbeResult
		err error
	)
	err = e.withRetry(ctx, func(callCtx context.Context) error {
		out, err = e.tlsProber.ProbeSTARTTLS(callCtx, host, port)
		return err
	})
	return out, err
}

func (e *Engine) fetchMTASTS(ctx context.Context, domain string) (string, error) {
	var (
		out string
		err error
	)
	err = e.withRetry(ctx, func(callCtx context.Context) error {
		out, err = e.mtaSTS.FetchPolicy(callCtx, domain)
		return err
	})
	return out, err
}
