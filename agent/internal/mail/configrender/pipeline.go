package configrender

import (
	"context"
	"time"
)

type Engine struct {
	renderer  *Renderer
	writer    *Writer
	validator *Validator
	activator *Activator
}

func NewEngine(stagingRoot string) *Engine {
	return &Engine{
		renderer:  NewRenderer(),
		writer:    NewWriter(stagingRoot),
		validator: NewValidator(),
		activator: NewActivator(),
	}
}

func (e *Engine) Apply(ctx context.Context, snapshot Snapshot) (ApplyResult, error) {
	bundle, err := e.renderer.Render(snapshot)
	if err != nil {
		return ApplyResult{}, err
	}

	if _, err = e.writer.Stage(bundle); err != nil {
		return ApplyResult{}, err
	}

	if err = e.validator.Validate(ctx, snapshot); err != nil {
		return ApplyResult{}, err
	}

	artifact, err := e.writer.BackupAndActivate(bundle)
	if err != nil {
		return ApplyResult{}, err
	}

	health, err := e.activator.ReloadAndHealthcheck(ctx, snapshot)
	if err != nil {
		rollbackErr := e.writer.Rollback(artifact)
		if rollbackErr != nil {
			return ApplyResult{}, rollbackErr
		}
		return ApplyResult{}, err
	}

	return ApplyResult{
		Revision:       bundle.Revision,
		ActivatedAt:    time.Now().UTC(),
		FilesActivated: artifact.ActivatedFiles,
		Health:         health,
	}, nil
}
